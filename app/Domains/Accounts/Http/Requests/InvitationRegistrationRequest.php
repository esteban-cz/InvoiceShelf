<?php

namespace App\Domains\Accounts\Http\Requests;

use App\Rules\IdnEmail;
use Illuminate\Foundation\Http\FormRequest;

class InvitationRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', new IdnEmail, 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invitation_token' => ['required', 'string'],
        ];
    }
}
