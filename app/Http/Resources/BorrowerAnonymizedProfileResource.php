<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerAnonymizedProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'risk_class' => $this->risk_class,
            'region' => $this->region,
            'loan_purpose' => $this->loan_purpose,
            'collateral_type' => $this->collateral_type,
            'age_group' => $this->age_group,
        ];
    }
}
