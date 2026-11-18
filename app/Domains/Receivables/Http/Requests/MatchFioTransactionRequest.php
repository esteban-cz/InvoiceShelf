<?php

namespace App\Domains\Receivables\Http\Requests;

use App\Domains\Accounts\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatchFioTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (int) Company::query()->whereKey($this->header('company'))->value('owner_id') === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['match', 'link', 'ignore', 'restore'])],
            'invoice_id' => ['required_if:action,match', 'nullable', 'integer', Rule::exists('invoices', 'id')->where('company_id', $this->header('company'))],
            'payment_id' => ['required_if:action,link', 'nullable', 'integer', Rule::exists('customer_payments', 'id')->where('company_id', $this->header('company'))],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }
}
