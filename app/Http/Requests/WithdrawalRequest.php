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
            // Either provide a raw IBAN or reference a saved one — not both
            'iban' => ['required_without:saved_iban_id', 'nullable', 'string', 'min:15', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4,30}$/'],
            'saved_iban_id' => ['required_without:iban', 'nullable', 'integer', 'exists:saved_ibans,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'iban.regex' => 'Невалиден IBAN формат.',
            'iban.required_without' => 'Изберете IBAN или въведете нов.',
            'saved_iban_id.required_without' => 'Изберете IBAN или въведете нов.',
            'amount.min' => 'Минималната сума за теглене е 10.00 €.',
        ];
    }
}
