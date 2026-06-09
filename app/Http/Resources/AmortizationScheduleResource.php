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
            'fees' => $this->fees,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            // Late tracking — null/0 unless this row was marked late.
            // Always present (not gated by ->when) so the frontend can rely
            // on the field shape regardless of status.
            'became_late_at' => $this->became_late_at,
            'days_late' => (int) $this->days_late,
        ];
    }
}
