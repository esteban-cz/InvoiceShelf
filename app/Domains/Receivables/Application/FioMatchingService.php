<?php

namespace App\Domains\Receivables\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Money\Models\Currency;
use App\Domains\Receivables\Application\Composition\PaymentAttributes;
use App\Domains\Receivables\Contracts\PaymentNumberAssigner;
use App\Domains\Receivables\Models\FioTransaction;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Receivables\Models\PaymentMethod;
use App\Domains\Sales\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FioMatchingService
{
    public function __construct(private readonly PaymentService $payments, private readonly PaymentNumberAssigner $numbers) {}

    public function autoMatch(FioTransaction $transaction): void
    {
        $this->locked($transaction, function (FioTransaction $row): void {
            if ($row->status !== 'pending' || $row->amount <= 0) {
                return;
            }
            $symbol = $this->symbol($row->variable_symbol);
            $matches = $this->invoices($row)->filter(fn (Invoice $invoice) => $symbol !== null
                && $this->symbol($invoice->payment_qr['variable_symbol'] ?? null) === $symbol
                && $this->iban($invoice->payment_qr['iban'] ?? '') === $row->account_iban);
            $reason = $symbol === null ? 'missing_symbol' : 'no_match';
            if ($matches->count() > 1) {
                $reason = 'ambiguous';
            } elseif ($matches->count() === 1) {
                $invoice = Invoice::query()->whereKey($matches->first()->id)->lockForUpdate()->firstOrFail();
                if ($invoice->type !== Invoice::TYPE_INVOICE || $invoice->status === Invoice::STATUS_DRAFT
                    || $invoice->currency?->code !== $row->currency_code
                    || (int) $invoice->customer?->currency_id !== (int) $invoice->currency_id
                    || $this->symbol($invoice->payment_qr['variable_symbol'] ?? null) !== $symbol
                    || $this->iban($invoice->payment_qr['iban'] ?? '') !== $row->account_iban) {
                    $reason = 'no_match';
                } elseif ((int) $invoice->due_amount !== $row->amount || $invoice->due_amount <= 0) {
                    $reason = 'amount_mismatch';
                } elseif ((string) $invoice->currency_id !== (string) CompanySetting::getSetting('currency', $row->company_id)) {
                    $reason = 'exchange_rate_required';
                } elseif ($this->existingPayments($row)->isNotEmpty()) {
                    $reason = 'possible_duplicate';
                } else {
                    $this->record($row, $invoice, 1);

                    return;
                }
            }
            $row->update(['reason' => $reason]);
        });
    }

    public function review(FioTransaction $transaction, array $input, int $actorId): FioTransaction
    {
        $this->locked($transaction, function (FioTransaction $row) use ($input, $actorId): void {
            if ($row->status === 'matched') {
                throw ValidationException::withMessages(['action' => __('fio_already_matched')]);
            }
            if ($input['action'] === 'ignore' || $input['action'] === 'restore') {
                abort_unless($row->amount > 0, 422, __('fio_incoming_only'));
                $row->update(['status' => $input['action'] === 'ignore' ? 'ignored' : 'pending', 'reason' => $input['action'] === 'ignore' ? 'manual' : 'review']);

                return;
            }
            abort_unless($row->status === 'pending' && $row->amount > 0, 422, __('fio_incoming_only'));
            if ($input['action'] === 'link') {
                $payment = Payment::query()->where('company_id', $row->company_id)->whereKey($input['payment_id'])->lockForUpdate()->firstOrFail();
                abort_unless((int) $payment->amount === $row->amount && $payment->currency?->code === $row->currency_code
                    && ! FioTransaction::query()->where('payment_id', $payment->id)->exists(), 422, __('fio_payment_mismatch'));
                $row->update(['payment_id' => $payment->id, 'status' => 'matched', 'reason' => 'linked']);

                return;
            }
            $invoice = $this->invoices($row)->where('id', $input['invoice_id'])->first();
            abort_unless($invoice, 422, __('fio_invoice_mismatch'));
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->due_amount > 0, 422, __('fio_invoice_mismatch'));
            $foreign = (string) $invoice->currency_id !== (string) CompanySetting::getSetting('currency', $row->company_id);
            if ($foreign && empty($input['exchange_rate'])) {
                throw ValidationException::withMessages(['exchange_rate' => __('fio_exchange_rate_required')]);
            }
            $this->record($row, $invoice, $foreign ? (float) $input['exchange_rate'] : 1, $actorId);
        });

        return $transaction->fresh('payment');
    }

    public function candidates(FioTransaction $row): array
    {
        $homeCurrency = (string) CompanySetting::getSetting('currency', $row->company_id);

        return [
            'invoices' => $this->invoices($row)->where('due_amount', '>', 0)->values()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id, 'number' => $invoice->invoice_number, 'customer' => $invoice->customer->name,
                'due_amount' => (int) $invoice->due_amount, 'currency_code' => $row->currency_code,
                'variable_symbol' => $invoice->payment_qr['variable_symbol'] ?? null,
                'requires_exchange_rate' => (string) $invoice->currency_id !== $homeCurrency,
            ])->all(),
            'payments' => $this->existingPayments($row, true)->values()->map(fn (Payment $payment) => [
                'id' => $payment->id, 'number' => $payment->payment_number, 'customer' => $payment->customer?->name,
                'date' => substr((string) $payment->payment_date, 0, 10), 'amount' => (int) $payment->amount,
            ])->all(),
        ];
    }

    private function invoices(FioTransaction $row): Collection
    {
        return Invoice::query()->with('customer')->where('company_id', $row->company_id)
            ->where('type', Invoice::TYPE_INVOICE)->where('status', '!=', Invoice::STATUS_DRAFT)
            ->where('currency_id', Currency::query()->where('code', $row->currency_code)->value('id'))
            ->orderByDesc('invoice_date')->get()
            ->filter(fn (Invoice $invoice) => (int) $invoice->customer?->currency_id === (int) $invoice->currency_id
                && (int) $invoice->customer?->company_id === (int) $row->company_id);
    }

    private function existingPayments(FioTransaction $row, bool $unlinkedOnly = false): Collection
    {
        $symbol = $this->symbol($row->variable_symbol);
        $invoiceIds = $symbol === null ? [] : $this->invoices($row)
            ->filter(fn (Invoice $invoice) => $this->symbol($invoice->payment_qr['variable_symbol'] ?? null) === $symbol
                && $this->iban($invoice->payment_qr['iban'] ?? '') === $row->account_iban)->modelKeys();

        return Payment::query()->with('customer')->where('company_id', $row->company_id)->where('amount', $row->amount)
            ->when($unlinkedOnly, fn ($query) => $query->whereNotIn('id', FioTransaction::query()->whereNotNull('payment_id')->select('payment_id')))
            ->where('currency_id', Currency::query()->where('code', $row->currency_code)->value('id'))
            ->where(function ($query) use ($row, $invoiceIds): void {
                $query->whereBetween('payment_date', [$row->transaction_date->copy()->subDays(3)->toDateString(), $row->transaction_date->copy()->addDays(3)->toDateString()]);
                if ($invoiceIds !== []) {
                    $query->orWhereHas('allocations', fn ($allocations) => $allocations->whereIn('invoice_id', $invoiceIds));
                }
            })->get();
    }

    private function record(FioTransaction $row, Invoice $invoice, float $rate, ?int $actorId = null): void
    {
        $connection = $row->connection;
        abort_unless($connection, 422, __('fio_connection_required'));
        $number = $this->numbers->next(new Payment, $row->company_id, $invoice->customer_id, true)->number;
        $input = ['customer_id' => $invoice->customer_id, 'payment_date' => $row->transaction_date->toDateString(),
            'payment_number' => $number, 'amount' => $row->amount, 'exchange_rate' => $rate];
        if ($connection->payment_method_id) {
            $input['payment_method_id'] = PaymentMethod::query()->where('company_id', $row->company_id)
                ->where('type', PaymentMethod::TYPE_GENERAL)->whereKey($connection->payment_method_id)->value('id');
        }
        $attributes = PaymentAttributes::fromInput($input, $row->company_id, $actorId ?? $connection->creator_id);
        $payment = $this->payments->create($attributes, [['invoice_id' => $invoice->id, 'amount' => min($row->amount, (int) $invoice->due_amount)]]);
        $row->update(['payment_id' => $payment->id, 'status' => 'matched', 'reason' => $actorId ? 'manual' : 'exact']);
    }

    private function locked(FioTransaction $transaction, callable $callback): void
    {
        DB::transaction(function () use ($transaction, $callback): void {
            // Serialize imports and number assignment across all this company's bank accounts.
            Company::query()->whereKey($transaction->company_id)->lockForUpdate()->firstOrFail();
            $callback(FioTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail());
        }, 3);
    }

    private function symbol(?string $value): ?string
    {
        return $value !== null && preg_match('/\A[0-9]{1,10}\z/', $value) ? (ltrim($value, '0') ?: '0') : null;
    }

    private function iban(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value));
    }
}
