<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'array'],
            // Every ledger type is filterable — a hardcoded subset here used
            // to 422-reject valid filters (buyback_*, early_repayment_*,
            // interest_*) for rows the unfiltered list happily returned.
            'type.*' => [Rule::in(Transaction::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];
    }
}
