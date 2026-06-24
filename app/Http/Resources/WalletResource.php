<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The three figures shown to the investor:
            'invested' => $this->invested,                  // Инвестирана сума
            'current_balance' => $this->currentBalance(),   // Текущо салдо (invested + accrued)
            'available' => $this->available,                // Свободни за теглене
            // Supporting buckets.
            'accrued' => $this->accrued,
            'reserved' => $this->reserved,
            'earned' => $this->earned,
            'total' => bcadd(bcadd($this->available, $this->reserved, 2), $this->invested, 2),
        ];
    }
}
