<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OriginatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'buyback' => $this->buyback,
            // F2 — expose the effective coverage type (per-originator override
            // OR platform default). Null when the originator has no buyback
            // at all, so the investor UI can skip the coverage row entirely.
            // buyback_trigger_days is intentionally NOT exposed — it's an
            // operational value relevant only to the detection cron.
            'buyback_coverage' => $this->when(
                (bool) $this->buyback,
                fn () => $this->buyback_coverage
                    ?? PlatformSetting::get('buyback_default_coverage'),
            ),
            'logo_path' => $this->logo_path,
        ];
    }
}
