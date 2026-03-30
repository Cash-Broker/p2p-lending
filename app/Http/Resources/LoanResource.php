<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    /**
     * Investor-facing loan resource.
     * Never exposes borrower_id or raw borrower data —
     * only the anonymized profile is visible to investors.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'funded_amount' => $this->funded_amount,
            'interest_rate' => $this->interest_rate,
            'term_months' => $this->term_months,
            'type' => $this->type,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'originator' => new OriginatorResource($this->whenLoaded('originator')),
            'anonymized_profile' => new BorrowerAnonymizedProfileResource($this->whenLoaded('anonymizedProfile')),
            'funded_percentage' => bccomp($this->amount, '0', 2) > 0
                ? (int) bcmul(bcdiv($this->funded_amount, $this->amount, 4), '100')
                : 0,
            'investors_count' => $this->whenCounted('investments', $this->investments_count),
            'amortization_schedule' => AmortizationScheduleResource::collection($this->whenLoaded('amortizationSchedules')),
        ];
    }
}
