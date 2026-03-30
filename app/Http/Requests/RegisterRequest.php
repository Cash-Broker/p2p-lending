<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            // Legal requirement: user must explicitly accept terms before registration.
            // Without this checkbox = no evidence of informed consent = lawsuit risk.
            'terms_accepted' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.required' => 'Трябва да приемете условията за ползване.',
            'terms_accepted.accepted' => 'Трябва да приемете условията за ползване.',
        ];
    }
}
