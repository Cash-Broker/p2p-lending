<?php

namespace App\Http\Requests;

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
            // SEC-01 (owner 2026-09-03): a withdrawal goes only to a saved IBAN the
            // owner confirmed by e-mail; WithdrawalService re-checks confirmation and
            // the cooling-off under lock. A raw IBAN in the request (stale PWA bundle,
            // scripted client) gets a clear 422 instead of being silently ignored.
            'saved_iban_id' => ['required', 'integer', Rule::exists('saved_ibans', 'id')->where('user_id', $this->user()->id)],
            'iban' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'saved_iban_id.required' => 'Изберете потвърден IBAN от профила си.',
            'saved_iban_id.integer' => 'Изберете потвърден IBAN от профила си.',
            'saved_iban_id.exists' => 'Изберете потвърден IBAN от профила си.',
            'iban.prohibited' => 'Тегления се правят само към потвърден IBAN от профила ви. Добавете и потвърдете IBAN, след което опитайте отново.',
            'amount.min' => 'Минималната сума за теглене е 10.00 €.',
        ];
    }
}
