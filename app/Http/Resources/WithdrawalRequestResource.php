<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'iban' => $this->maskedIban(),
            'status' => $this->status,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
