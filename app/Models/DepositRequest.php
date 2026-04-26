<?php

namespace App\Models;

use Database\Factories\DepositRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DepositRequest extends Model
{
    /** @use HasFactory<DepositRequestFactory> */
    use HasFactory, \App\Traits\Auditable;

    /**
     * Code lifetime in days. Picked generously because bank wires from
     * different countries can take 3–5 business days; 30 days gives the
     * user breathing room while still rotating stale codes.
     */
    public const CODE_LIFETIME_DAYS = 30;

    protected $fillable = [
        'user_id',
        'amount',
        'reference_code',
        'bank_reference',
        'status',
        'admin_note',
        'confirmed_at',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Auto-generate code + expiry on first save. Both are user-facing
        // contract: code = what user pastes in bank reference; expires_at
        // = how long that code is honoured. Setting both here keeps every
        // creation path (controller, service, factory) consistent.
        static::creating(function (DepositRequest $request) {
            if (empty($request->reference_code)) {
                $request->reference_code = 'DEP-' . strtoupper(Str::random(8));
            }
            if (empty($request->expires_at)) {
                $request->expires_at = now()->addDays(self::CODE_LIFETIME_DAYS);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * NULL `expires_at` is a legacy row from before the refactor —
     * treated as non-expiring to preserve old behaviour.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
