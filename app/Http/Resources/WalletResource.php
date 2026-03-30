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
            'invested' => $this->invested,
            'earned' => $this->earned,
            'total' => bcadd($this->available, $this->invested, 2),
        ];
    }
}
