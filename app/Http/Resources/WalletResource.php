<?php

namespace App\Http\Resources;

use App\Services\WalletService;
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
            'available' => $this->available,                // Свободни (за инвестиране)
            // Свободни за ТЕГЛЕНЕ — differs from `available` exactly by the
            // conditional bonuses the investor has not earned yet. They may be
            // invested, not cashed out (Reni 2026-08-18).
            'withdrawable' => app(WalletService::class)->withdrawableBalance($this->resource),
            // Supporting buckets.
            'accrued' => $this->accrued,
            'reserved' => $this->reserved,
            'earned' => $this->earned,
            'total' => bcadd(bcadd($this->available, $this->reserved, 2), $this->invested, 2),
        ];
    }
}
