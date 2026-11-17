<?php

namespace App\Domains\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceBankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'iban' => ['required', 'string', 'max:64'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
