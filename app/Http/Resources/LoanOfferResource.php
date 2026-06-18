<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Investor-facing offer: the payout structure + its rate. Profit projections
 * for a chosen amount come from the /offer-quotes endpoint, not here.
 *
 * @mixin \App\Models\LoanOffer
 */
class LoanOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payout_type' => $this->payout_type->value,
            'label' => $this->payout_type->label(),
            'description' => $this->payout_type->description(),
            'interest_rate' => (string) $this->interest_rate,
        ];
    }
}
