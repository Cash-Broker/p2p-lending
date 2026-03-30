<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'role' => $this->role,
            'kyc_status' => $this->kyc_status,
            'phone' => $this->phone,
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'created_at' => $this->created_at,
        ];
    }
}
