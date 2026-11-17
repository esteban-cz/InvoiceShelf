<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Application\CompanySignatureService;
use App\Domains\Accounts\Models\Company;
use App\Domains\Sales\Models\Invoice;

class InvoiceSignatureService
{
    public function __construct(private readonly CompanySignatureService $signatures) {}

    /**
     * Snapshot the small signature image with the document, so changing or
     * removing the company image never rewrites previously issued invoices.
     */
    public function configure(array $attributes, ?Invoice $invoice = null): array
    {
        $enabled = (bool) ($attributes['sign_document'] ?? $invoice?->sign_document ?? true);
        $snapshot = $invoice?->document_signature;

        if ($snapshot === null || ($enabled && empty($snapshot['image']))) {
            $snapshot = $this->signatures->settings(Company::query()->findOrFail($attributes['company_id']));
        }

        $attributes['sign_document'] = $enabled;
        $attributes['document_signature'] = $snapshot;

        return $attributes;
    }
}
