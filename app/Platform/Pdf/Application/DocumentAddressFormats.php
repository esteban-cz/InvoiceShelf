<?php

namespace App\Platform\Pdf\Application;

use App\Domains\Accounts\Models\CompanySetting;

/** Invoice address settings are the shared formats for every sales document. */
class DocumentAddressFormats
{
    private const SETTINGS = [
        'company' => 'invoice_company_address_format',
        'billing' => 'invoice_billing_address_format',
        'shipping' => 'invoice_shipping_address_format',
    ];

    public function format(string $address, int $companyId): string
    {
        return (string) CompanySetting::getSetting(self::SETTINGS[$address], $companyId);
    }

    public function fingerprint(int $companyId): string
    {
        $keys = [...array_values(self::SETTINGS), 'language'];
        $settings = CompanySetting::getSettings($keys, $companyId);

        return hash('sha256', json_encode(array_map(fn (string $key) => $settings->get($key), $keys), JSON_THROW_ON_ERROR));
    }
}
