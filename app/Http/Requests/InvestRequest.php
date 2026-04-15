<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:50', 'max:999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Минималната инвестиция е 50.00 €.',
        ];
    }
}
