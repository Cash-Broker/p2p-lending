<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'available' => $this->available,
            'reserved' => $this->reserved,
            'invested' => $this->invested,
            'earned' => $this->earned,
            'total' => bcadd(bcadd($this->available, $this->reserved, 2), $this->invested, 2),
        ];
    }
}
