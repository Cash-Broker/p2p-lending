<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'invested_at' => $this->invested_at,
            // Chosen-offer snapshot (null for legacy investments).
            'loan_offer_id' => $this->loan_offer_id,
            'interest_rate' => $this->interest_rate,
            'payout_type' => $this->payout_type?->value,
            'payout_label' => $this->payout_type?->label(),
            'loan' => new LoanResource($this->whenLoaded('loan')),
        ];
    }
}
