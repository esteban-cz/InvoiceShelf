<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceBankAccount;
use App\Support\InvoiceVariableSymbolFormat;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Validation\ValidationException;

final class InvoicePaymentQr
{
    private const MAX_AMOUNT = 999_999_999;

    /** @param array{iban: string, variable_symbol?: string|null, save_account?: bool} $input */
    public function configure(array $input, int $companyId, int $currencyId): array
    {
        if (Currency::query()->find($currencyId)?->code !== 'CZK') {
            throw ValidationException::withMessages(['payment_qr.iban' => __('payment_qr_czk_only')]);
        }

        $iban = $this->requireValidAccount($input['iban'] ?? '', 'payment_qr.iban');
        $bank_name = array_key_exists('bank_name', $input)
            ? trim((string) $input['bank_name'])
            : InvoiceBankAccount::query()->where('company_id', $companyId)->where('iban', $iban)->value('bank_name');

        if ($input['save_account'] ?? false) {
            $this->saveAccount($companyId, $iban, $bank_name);
        }

        return [
            'iban' => $iban,
            'variable_symbol' => $input['variable_symbol'] ?? null,
            ...($bank_name ? ['bank_name' => $bank_name] : []),
        ];
    }

    public function requireValidAccount(string $input, string $field): string
    {
        return $this->normalizeAccount($input)
            ?? throw ValidationException::withMessages([$field => __('payment_qr_invalid_account')]);
    }

    public function saveAccount(int $companyId, string $normalizedIban, ?string $bank_name = null): void
    {
        $key = ['company_id' => $companyId, 'iban' => $normalizedIban];

        if ($bank_name !== null) {
            InvoiceBankAccount::query()->updateOrCreate($key, ['bank_name' => trim($bank_name) ?: null]);
        } else {
            InvoiceBankAccount::query()->firstOrCreate($key);
        }
    }

    public function savedAccounts(int $company_id, bool $details): array
    {
        return InvoiceBankAccount::query()->where('company_id', $company_id)->orderBy('iban')->get()
            ->map(fn (InvoiceBankAccount $account) => $details ? [
                'iban' => $account->iban,
                'account_number' => $this->domesticAccount($account->iban),
                'bank_name' => $account->bank_name,
            ] : $account->iban)->all();
    }

    /** The Czech account number is encoded losslessly in a valid Czech IBAN. */
    public function domesticAccount(string $input): ?string
    {
        $iban = $this->normalizeAccount($input);

        if ($iban === null) {
            return null;
        }

        $prefix = ltrim(substr($iban, 8, 6), '0');

        return ($prefix !== '' ? $prefix.'-' : '').ltrim(substr($iban, 14), '0').'/'.substr($iban, 4, 4);
    }

    public function payload(Invoice $invoice): ?string
    {
        $payment = $invoice->payment_qr;
        $amount = (int) $invoice->due_amount;

        if ($payment === null || $invoice->isCreditNote() || $amount <= 0
            || $amount > self::MAX_AMOUNT || $invoice->currency?->code !== 'CZK') {
            return null;
        }

        $payload = sprintf(
            'SPD*1.0*ACC:%s*AM:%d.%02d*CC:CZK',
            $payment['iban'],
            intdiv($amount, 100),
            $amount % 100,
        );

        return $payment['variable_symbol'] !== null
            ? $payload.'*X-VS:'.$payment['variable_symbol']
            : $payload;
    }

    public function defaultVariableSymbol(Invoice $invoice, int $sequence): ?string
    {
        $setting = CompanySetting::getSetting(InvoiceVariableSymbolFormat::SETTING, $invoice->company_id);
        $format = InvoiceVariableSymbolFormat::tryFrom((string) $setting) ?? InvoiceVariableSymbolFormat::Manual;

        if ($format === InvoiceVariableSymbolFormat::Manual) {
            return null;
        }

        return $format->generate($invoice->invoice_number, $invoice->invoice_date, $sequence)
            ?? throw ValidationException::withMessages(['payment_qr.variable_symbol' => __('payment_qr_default_symbol_unavailable')]);
    }

    public function image(Invoice $invoice): ?string
    {
        $payload = $this->payload($invoice);

        if ($payload === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 4), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($payload));
    }

    public function normalizeAccount(string $input): ?string
    {
        $account = strtoupper(preg_replace('/\s+/', '', $input));

        if (preg_match('/\ACZ[0-9]{22}\z/', $account)) {
            $digits = substr($account, 4).'1235'.substr($account, 2, 2);

            return $this->mod97($digits) === 1
                && substr($account, 4, 4) !== '0000'
                && $this->validDomesticParts(substr($account, 8, 6), substr($account, 14))
                ? $account : null;
        }

        if (! preg_match('/\A(?:([0-9]{1,6})-)?([0-9]{1,10})\/([0-9]{4})\z/', $account, $matches)
            || $matches[3] === '0000'
            || ! $this->validDomesticParts($matches[1], $matches[2])) {
            return null;
        }

        $bban = $matches[3].str_pad($matches[1], 6, '0', STR_PAD_LEFT).str_pad($matches[2], 10, '0', STR_PAD_LEFT);

        return sprintf('CZ%02d%s', 98 - $this->mod97($bban.'123500'), $bban);
    }

    private function validDomesticParts(string $prefix, string $number): bool
    {
        return trim($number, '0') !== '' && $this->mod11($prefix) === 0 && $this->mod11($number) === 0;
    }

    private function mod11(string $number): int
    {
        $sum = 0;
        $weights = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6];

        foreach (str_split(strrev($number)) as $position => $digit) {
            $sum += (int) $digit * $weights[$position];
        }

        return $sum % 11;
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
