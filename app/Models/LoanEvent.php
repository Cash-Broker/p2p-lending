<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only lifecycle log per loan.
 *
 * Immutability is enforced at THREE layers:
 *   1. DB triggers (prevent_loan_event_update, prevent_loan_event_delete) —
 *      catches anything, including raw SQL outside the Eloquent path.
 *   2. CHECK constraints (event_type enum, triggered_by enum,
 *      triggered_by_user_id consistency, status_pair) — catches malformed
 *      data shape.
 *   3. Application-level overrides on update() and delete() — surfaces a
 *      friendly error in code paths instead of waiting for a generic
 *      MySQL error 1644.
 *
 * The third layer was added per the F1 spec: "DB triggers primary,
 * application error като immediate friendly error".
 *
 * UPDATED_AT = null mirrors Transaction — no `updated_at` column makes the
 * append-only intent obvious to the next reader of the schema.
 *
 * Event-type constants reflect the pre-expanded enum from migration F1
 * (the F2/F3/F4 placeholders are listed for forward compatibility — they
 * are not written by F1 services).
 */
class LoanEvent extends Model
{
    public const UPDATED_AT = null;

    // F1 events — written by LoanStatusUpdaterService
    public const TYPE_WENT_LATE = 'went_late';
    public const TYPE_RECOVERED_FROM_LATE = 'recovered_from_late';
    public const TYPE_WENT_DEFAULT = 'went_default';

    // Phase F2 — buyback (placeholders)
    public const TYPE_BUYBACK_TRIGGERED = 'buyback_triggered';
    public const TYPE_BUYBACK_COMPLETED = 'buyback_completed';

    // Phase F3 — early repayment (placeholders)
    public const TYPE_EARLY_REPAYMENT_REQUESTED = 'early_repayment_requested';
    public const TYPE_EARLY_REPAYMENT_COMPLETED = 'early_repayment_completed';

    // Phase F4 — fees (placeholder)
    public const TYPE_FEE_APPLIED = 'fee_applied';

    // Generic fallback for transitions not covered above
    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TRIGGERED_BY_SYSTEM = 'system';
    public const TRIGGERED_BY_ADMIN = 'admin';

    protected $fillable = [
        'loan_id',
        'event_type',
        'from_status',
        'to_status',
        'triggered_by',
        'triggered_by_user_id',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * App-level immutability guard. Throws BEFORE the DB trigger fires so
     * developers get a friendly Laravel exception instead of a generic
     * SQLSTATE 45000 from MySQL.
     *
     * The DB trigger is still the source of truth — even raw queries that
     * bypass Eloquent are blocked.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('LoanEvent records are immutable and cannot be updated.');
    }

    public function delete(): bool
    {
        throw new LogicException('LoanEvent records are immutable and cannot be deleted.');
    }
}
