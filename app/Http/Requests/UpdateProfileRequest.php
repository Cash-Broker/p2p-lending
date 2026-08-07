<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No Unicode control characters — mirrors RegisterRequest; the
            // name is rendered in admin-facing emails/notifications where
            // newlines would enable markdown block injection (2026-08-07
            // security review).
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Името съдържа непозволени знаци.',
        ];
    }
}
