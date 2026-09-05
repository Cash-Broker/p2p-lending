<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single installment in an investment's payout schedule — the offer-based
 * counterpart to AmortizationSchedule (which is per-loan). One row per due
 * date per investment; generated at funded → active from the investment's
 * snapshotted rate + payout type.
 */
class InvestmentSchedule extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_LATE = 'late';

    /**
     * Cancelled by an early closure (Reni 2026-08-18) — the borrower repaid
     * that principal ahead of plan, so the installment will never be paid out.
     * Deliberately NOT `paid`: nothing was received, and everything that counts
     * received money (portfolio totals, the conditional-bonus condition) must
     * skip it.
     */
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'investment_id',
        'loan_id',
        'due_date',
        'principal',
        'interest',
        'total',
        'status',
        'paid_at',
        'closed_at',
        'became_late_at',
        'days_late',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'became_late_at' => 'datetime',
            'days_late' => 'integer',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
