<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A flash promo window on one loan (Reni 2026-08-14): invest while it runs
 * → an UPFRONT bonus (bonus_percent of the invested amount) lands in
 * `available` immediately, as a TYPE_BONUS ledger row. Display drives FOMO
 * («офертата валидна за 60 мин»); the money mechanics reuse the existing
 * admin-bonus rails.
 *
 * Lifecycle: created by an admin with a fixed [starts_at, ends_at) window;
 * ends by expiry or by the admin's «Прекрати» (cancelled_at). No edits
 * after creation — a live promo's terms must not drift under investors'
 * feet (same freeze philosophy as investment snapshots); cancel + recreate
 * instead. bonus_paid_total advances only under row lock in
 * PromotionService::grantInvestBonus, and the DB CHECK pins it inside
 * budget_cap.
 */
class LoanPromotion extends Model
{
    use Auditable;

    protected $fillable = [
        'loan_id',
        'bonus_percent',
        'budget_cap',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bonus_percent' => 'decimal:2',
            'budget_cap' => 'decimal:2',
            'bonus_paid_total' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Inside the time window and not cancelled (budget/loan checks are separate). */
    public function isRunning(): bool
    {
        return $this->cancelled_at === null
            && $this->starts_at->lte(now())
            && $this->ends_at->gt(now());
    }

    /** Remaining bonus budget, or null when uncapped. bcmath string (2dp). */
    public function remainingBudget(): ?string
    {
        if ($this->budget_cap === null) {
            return null;
        }

        $remaining = bcsub((string) $this->budget_cap, (string) $this->bonus_paid_total, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    /** Promotions currently inside their window and not cancelled. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }
}
