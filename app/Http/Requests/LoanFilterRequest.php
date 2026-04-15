<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoanFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'array'],
            'type.*' => ['in:consumer,business,mortgage,bridge'],
            'originator_id' => ['nullable', 'array'],
            'originator_id.*' => ['integer', 'exists:originators,id'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'interest_rate_min' => ['nullable', 'numeric', 'min:0'],
            'interest_rate_max' => ['nullable', 'numeric', 'min:0'],
            'term_min' => ['nullable', 'integer', 'min:1'],
            'term_max' => ['nullable', 'integer', 'min:1'],
            'risk_class' => ['nullable', 'array'],
            'risk_class.*' => ['in:A,B,C,D,E'],
            'sort' => ['nullable', 'in:newest,highest_rate,shortest_term,most_funded'],
        ];
    }
}
