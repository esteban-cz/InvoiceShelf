<?php

namespace App\Domains\Accounts\Http\Requests;

use App\Domains\Accounts\Application\MemberVisibleSettings;
use App\Support\InvoiceVariableSymbolFormat;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payload behind writing preferences: one map of option names to values.
 *
 * Most values are stored as submitted, and the currency is guarded by the
 * controller once the books are open. The mail transport and
 * module settings are refused, because each has an endpoint of its own that
 * validates it.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * Owner-only, but the gate that says so runs in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $receivingAccount = 'settings.invoice_default_receiving_iban';

        return [
            'settings' => [
                'bail',
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $visibility = app(MemberVisibleSettings::class);
                    $refused = array_filter(array_keys($value), fn (int|string $key): bool => $visibility->isPrivate((string) $key));

                    if ($refused !== []) {
                        $fail('These settings have their own page and cannot be changed here: '.implode(', ', $refused).'.');
                    }
                },
            ],
            'settings.'.InvoiceVariableSymbolFormat::SETTING => ['sometimes', 'required', Rule::enum(InvoiceVariableSymbolFormat::class)],
            $receivingAccount => [
                'bail',
                'sometimes',
                'required',
                'string',
                Rule::unless($this->input($receivingAccount) === 'none', [
                    'regex:/\ACZ[0-9]{22}\z/',
                    Rule::exists('invoice_bank_accounts', 'iban')->where('company_id', $this->header('company')),
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.invoice_default_receiving_iban.exists' => __('payment_qr_default_account_invalid'),
            'settings.invoice_default_receiving_iban.regex' => __('payment_qr_default_account_invalid'),
        ];
    }
}
