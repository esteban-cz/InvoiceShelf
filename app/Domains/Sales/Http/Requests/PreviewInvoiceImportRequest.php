<?php

namespace App\Domains\Sales\Http\Requests;

use App\Domains\Sales\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class PreviewInvoiceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf', 'max:10240']];
    }
}
