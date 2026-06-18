<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $loan = $this->route('loan');

        return [
            'amount' => ['required', 'numeric', 'min:50', 'max:999999.99', 'decimal:0,2'],
            // The investor must pick one of THIS loan's enabled offers. The
            // belongs-to-loan + is_enabled checks are baked into the exists rule;
            // InvestmentService re-checks inside the locked transaction (TOCTOU).
            'loan_offer_id' => [
                'required', 'integer',
                Rule::exists('loan_offers', 'id')->where(function ($query) use ($loan) {
                    $query->where('loan_id', $loan?->id)->where('is_enabled', true);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Минималната инвестиция е 50.00 €.',
            'loan_offer_id.required' => 'Моля изберете оферта.',
            'loan_offer_id.exists' => 'Избраната оферта не е налична за този кредит.',
        ];
    }
}
