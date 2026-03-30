<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:10', 'max:999999.99'],
            'iban' => ['required', 'string', 'min:15', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'iban.regex' => 'Невалиден IBAN формат.',
            'amount.min' => 'Минималната сума за теглене е 10.00 €.',
        ];
    }
}
