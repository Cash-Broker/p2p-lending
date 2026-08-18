<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One granted bonus and the condition it is still waiting on (Reni
 * 2026-08-18). The money itself lives in the wallet's `bonus_locked` bucket;
 * this row carries the WHY: how much had to be invested, from when, how many
 * installments must be served, and what happened in the end.
 *
 * Two sources, one rule:
 *   • admin — «Начисли бонус», base typed in by the admin (за 5000 → 50 €).
 *   • promo — flash-promo upfront bonus, base = the investment that earned it.
 *
 * Released grants keep their row: it is the evidence of what the investor was
 * promised and when the platform paid it.
 */
class BonusGrant extends Model
{
    use Auditable;

    public const STATUS_LOCKED = 'locked';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_PROMO = 'promo';

    /**
     * Installments a qualifying investment must have served before the bonus
     * unlocks («след третия падеж»). An investment whose plan has FEWER rows
     * than this — capitalized pays once, at maturity — unlocks on its last
     * one instead (Reni 2026-08-18: «бонусът се отключва [на падежа]»).
     */
    public const DEFAULT_REQUIRED_INSTALLMENTS = 3;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'amount',
        'base_amount',
        'required_installments',
        'source',
        'granted_by',
        'loan_promotion_id',
        'investment_id',
        'reason',
        'status',
        'qualifies_from',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'required_installments' => 'integer',
            'qualifies_from' => 'datetime',
            'released_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function releaseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'release_transaction_id');
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(LoanPromotion::class, 'loan_promotion_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** @param  Builder<BonusGrant>  $query */
    public function scopeLocked(Builder $query): void
    {
        $query->where('status', self::STATUS_LOCKED);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
