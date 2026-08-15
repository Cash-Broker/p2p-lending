<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per investor per Sofia calendar day: how many times they ENTERED
 * the dashboard (see DashboardController::recordVisit). Read-model for the
 * admin's activity view + the morning digest; writes happen via a raw
 * upsert, never through this model.
 */
class UserVisitDay extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'entries' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
