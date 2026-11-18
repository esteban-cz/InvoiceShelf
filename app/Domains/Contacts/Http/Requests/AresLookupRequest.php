<?php

namespace App\Domains\Contacts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AresLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ico' => preg_replace('/\s+/', '', (string) $this->route('ico'))]);
    }

    public function rules(): array
    {
        return ['ico' => ['required', 'string', 'regex:/\A[0-9]{8}\z/']];
    }
}
