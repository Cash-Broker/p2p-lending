<?php

namespace App\Http\Requests;

use App\Rules\ValidIban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:10', 'max:999999.99', 'decimal:0,2'],
            // Either provide a raw IBAN or reference a saved one — not both.
            // ValidIban: ISO 13616 mod-97 checksum + SEPA country whitelist.
            'iban' => ['required_without:saved_iban_id', 'nullable', 'string', 'min:15', 'max:34', new ValidIban],
            'saved_iban_id' => [
                'required_without:iban', 'nullable', 'integer',
                Rule::exists('saved_ibans', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'iban.required_without' => 'Изберете IBAN или въведете нов.',
            'saved_iban_id.required_without' => 'Изберете IBAN или въведете нов.',
            'amount.min' => 'Минималната сума за теглене е 10.00 €.',
        ];
    }
}
