<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmortizationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'due_date' => $this->due_date->format('Y-m-d'),
            'principal' => $this->principal,
            'interest' => $this->interest,
            'total' => $this->total,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
        ];
    }
}
