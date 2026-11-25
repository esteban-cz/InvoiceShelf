<?php

namespace App\Domains\Accounts\Http\Requests;

use App\Domains\Accounts\Application\MemberVisibleSettings;
use App\Domains\Receivables\Models\PaymentMethod;
use App\Domains\Sales\Application\InvoicePaymentLinks;
use App\Rules\PaymentLink;
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
            'settings.'.InvoicePaymentLinks::SETTING => ['nullable', 'string', 'max:'.InvoicePaymentLinks::MAX_LENGTH, new PaymentLink],
            'settings.'.InvoicePaymentLinks::QR_SETTING => ['sometimes', Rule::in(['YES', 'NO'])],
            'settings.'.InvoiceVariableSymbolFormat::SETTING => ['sometimes', 'required', Rule::enum(InvoiceVariableSymbolFormat::class)],
            'settings.default_payment_method_id' => [
                'sometimes', 'required',
                Rule::unless($this->input('settings.default_payment_method_id') === 'none', [
                    'integer',
                    Rule::exists('payment_methods', 'id')->where('company_id', $this->header('company'))->where('type', PaymentMethod::TYPE_GENERAL),
                ]),
            ],
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'settings.'.InvoicePaymentLinks::SETTING => 'payment link',
            'settings.'.InvoicePaymentLinks::QR_SETTING => 'QR code setting',
        ];
    }

    /**
     * Preferences the application keeps itself are dropped rather than refused:
     * the preferences page sends back every setting it was given, these
     * included, and nothing may change them.
     */
    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (! is_array($settings)) {
            return;
        }

        $visibility = app(MemberVisibleSettings::class);

        $this->merge(['settings' => array_filter(
            $settings,
            fn (int|string $key): bool => ! $visibility->isKeptByTheApplication((string) $key),
            ARRAY_FILTER_USE_KEY,
        )]);
    }

    /**
     * An emptied payment link arrives as null (ConvertEmptyStringsToNull),
     * and a setting cannot hold null, so it is stored as an empty string:
     * no link.
     */
    protected function passedValidation(): void
    {
        $settings = $this->input('settings');

        if (array_key_exists(InvoicePaymentLinks::SETTING, $settings) && $settings[InvoicePaymentLinks::SETTING] === null) {
            $this->merge(['settings' => [...$settings, InvoicePaymentLinks::SETTING => '']]);
        }
    }
}
