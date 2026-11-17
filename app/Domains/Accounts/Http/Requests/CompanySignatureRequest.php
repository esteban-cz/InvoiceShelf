<?php

namespace App\Domains\Accounts\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompanySignatureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'issuer_name' => ['required', 'string', 'max:255'],
            'signature' => ['sometimes', 'nullable', 'image', 'mimes:png,jpg,jpeg', 'max:256', 'dimensions:max_width=2400,max_height=1200'],
            'remove_signature' => ['sometimes', 'boolean'],
        ];
    }
}
