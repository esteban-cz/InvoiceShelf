<?php

namespace App\Domains\Receivables\Http\Requests;

use App\Domains\Accounts\Models\Company;
use App\Domains\Receivables\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFioConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (int) Company::query()->whereKey($this->header('company'))->value('owner_id') === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'token' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'regex:/\A[a-zA-Z0-9]{64}\z/'],
            'sync_from' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:'.today('Europe/Prague')->subDays(89)->toDateString(), 'before_or_equal:'.today('Europe/Prague')->toDateString()],
            'enabled' => ['required', 'boolean'], 'auto_match' => ['required', 'boolean'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $this->header('company'))->where('type', PaymentMethod::TYPE_GENERAL)],
        ];
    }
}
