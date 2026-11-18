<?php

namespace App\Domains\Sales\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceImportResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'private, no-store');
    }

    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip([
            'filename', 'pages', 'ocr', 'format', 'invoice_number', 'invoice_date', 'due_date', 'currency_code', 'currency',
            'total', 'items', 'notes', 'issuer_name', 'supplier', 'customer', 'matched_customer_id', 'duplicate_number',
            'payment_qr', 'warnings', 'source_text', 'taxes', 'tax_mode',
        ]));
    }
}
