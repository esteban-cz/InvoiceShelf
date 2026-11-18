<?php

namespace App\Domains\Sales\Application\Import;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\InvoicePaymentQr;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Read-only suggestions. Persistence always uses the normal, reviewed invoice form. */
class InvoiceImportService
{
    public function __construct(
        private readonly InvoicePdfExtractor $extractor,
        private readonly InvoiceImportParser $parser,
        private readonly InvoicePaymentQr $qr,
    ) {}

    public function preview(UploadedFile $file, int $company_id): array
    {
        $extraction = $this->extractor->extract($file);
        $data = $this->parser->parse($extraction['text']);
        $data['warnings'] = array_merge($data['warnings'], $extraction['warnings']);
        $data['filename'] = mb_substr(basename($file->getClientOriginalName()), 0, 255);
        $data['pages'] = $extraction['pages'];
        $data['ocr'] = $extraction['ocr'];
        $data['source_text'] = mb_substr($extraction['text'], 0, 100_000);
        $currency = $data['currency_code'] ? Currency::query()->where('code', $data['currency_code'])->first() : null;
        $data['currency'] = $currency?->only(['id', 'name', 'code', 'symbol', 'precision', 'thousand_separator', 'decimal_separator', 'swap_currency_symbol']);
        if ($data['currency_code'] && ! $currency) {
            $data['warnings'][] = 'currency_unavailable';
        }
        $data['customer']['country_id'] = $data['customer']['country_code']
            ? Country::query()->where('code', $data['customer']['country_code'])->value('id') : null;
        $data['matched_customer_id'] = $this->matchCustomer($data, $company_id, $currency);
        $data['duplicate_number'] = $data['invoice_number'] && Invoice::query()->where('company_id', $company_id)
            ->where('invoice_number', $data['invoice_number'])->exists();
        if ($data['duplicate_number']) {
            $data['warnings'][] = 'duplicate_number';
        }
        if ($data['payment_qr']) {
            $iban = $this->qr->normalizeAccount((string) $data['payment_qr']['iban']);
            if ($data['currency_code'] === 'CZK' && $iban) {
                $data['payment_qr']['iban'] = $iban;
                $data['payment_qr']['save_account'] = false;
            } else {
                $data['payment_qr'] = null;
                $data['warnings'][] = 'bank_details_unavailable';
            }
        }
        $data['tax_mode'] = CompanySetting::getSetting('tax_per_item', $company_id) === 'YES' ? 'YES' : 'NO';
        $data['taxes'] = [];
        $rates = collect($data['items'])->pluck('vat_percent')->filter(fn ($rate) => $rate !== null)->unique()->values();
        $types = TaxType::query()->where('company_id', $company_id)->where('transaction_type', 'sales')
            ->where('type', TaxType::TYPE_GENERAL)->where('compound_tax', false)->get()
            ->filter(fn (TaxType $type) => $type->calculation_type !== 'fixed');
        foreach ($data['items'] as &$item) {
            $item['taxes'] = [];
            if (! $item['vat_percent']) {
                continue;
            }
            $matches = $types->filter(fn (TaxType $type) => abs($type->percent - $item['vat_percent']) < 0.0001);
            if ($matches->count() !== 1 || ($data['tax_mode'] === 'NO' && ($rates->count() !== 1 || collect($data['items'])->contains(fn ($row) => $row['vat_percent'] === null)))) {
                $data['warnings'][] = 'taxes_need_review';

                continue;
            }
            $tax = $matches->first()->only(['name', 'percent', 'compound_tax', 'calculation_type', 'fixed_amount', 'type']);
            $tax['tax_type_id'] = $matches->first()->id;
            if ($data['tax_mode'] === 'YES') {
                $item['taxes'] = [$tax];
            } else {
                $data['taxes'] = [$tax];
            }
        }
        unset($item);
        if ($extraction['ocr']) {
            $data['warnings'][] = 'ocr_used';
        }
        $data['warnings'] = array_values(array_unique($data['warnings']));

        return $data;
    }

    private function matchCustomer(array &$data, int $company_id, ?Currency $currency): ?int
    {
        if (! Gate::allows('viewAny', Customer::class)) {
            return null;
        }
        $party = $data['customer'];
        $query = Customer::query()->where('company_id', $company_id);
        if ($party['registration_number']) {
            $matches = (clone $query)->where('registration_number', $party['registration_number'])->limit(2)->get();
        } elseif ($party['tax_id']) {
            $matches = (clone $query)->where('tax_id', $party['tax_id'])->limit(2)->get();
        } elseif ($party['name']) {
            // Exact names only; spelling similarities never select another customer's account.
            $normalize = fn ($name) => Str::lower(Str::squish((string) $name));
            $name = $normalize($party['name']);
            $matches = $query->select(['id', 'name', 'company_name', 'currency_id'])->get()
                ->filter(fn ($customer) => $normalize($customer->name) === $name || $normalize($customer->company_name) === $name);
        } else {
            return null;
        }
        if ($matches->count() > 1) {
            $data['warnings'][] = 'customer_ambiguous';
        }
        if ($matches->count() !== 1) {
            return null;
        }
        if ($currency && (int) $matches->first()->currency_id !== (int) $currency->id) {
            $data['warnings'][] = 'customer_currency_mismatch';

            return null;
        }

        return (int) $matches->first()->id;
    }
}
