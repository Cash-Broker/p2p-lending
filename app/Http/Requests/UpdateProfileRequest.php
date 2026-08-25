<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
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
            // security review). `sometimes`: the PhoneRequiredModal submits
            // ONLY the phone — forcing it to co-submit the stored name would
            // hard-lock any pre-2026-08-07 account whose name fails the
            // control-char regex (422 on a field the modal can't edit).
            'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            // Mandatory since 2026-08-25 (mirrors RegisterRequest): the
            // profile must always carry a reachable number, and an update may
            // not clear it. This is also the endpoint the blocking
            // PhoneRequiredModal uses to backfill pre-requirement accounts.
            'phone' => ['required', 'string', 'max:32', new ValidPhone],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Името съдържа непозволени знаци.',
            'phone.required' => 'Телефонът е задължителен.',
        ];
    }
}
