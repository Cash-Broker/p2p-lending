<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\AmortizationScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-loan installment rows. Two kinds share the table (PAY-13, owner 2026-09-03):
 *
 *   • plan_kind NULL — the LEGACY per-loan amortization schedule (byte-identical
 *     semantics: the admin settles rows through «Погашения», RepaymentService
 *     distributes them pro-rata).
 *   • plan_kind 'borrower_tracker' — the BORROWER TRACKING PLAN of an OFFER loan:
 *     dates drive late detection / recovery / buyback eligibility; amounts are
 *     informational and are NEVER distributed (investors are paid by their own
 *     investment_schedules). `paid_at` = moment the admin recorded the payment
 *     (recovery rule R1 needs paid_at ≥ became_late_at), `borrower_paid_on` = the
 *     attested payment date, `recorded_by` = admin id (plain id, no FK).
 *
 * Every NEW reader of amortization rows must pick a scope on purpose:
 * legacyPlan() for anything that touches money or funding, borrowerTracker()
 * for the tracker; LateDetection/LoanStatusUpdater/BuybackEligibility read both.
 */
class AmortizationSchedule extends Model
{
    public const PLAN_KIND_BORROWER_TRACKER = 'borrower_tracker';

    use Auditable;

    /** @use HasFactory<AmortizationScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'due_date',
        'principal',
        'interest',
        'total',
        'fees',
        'status',
        'paid_at',
        'became_late_at',
        'days_late',
        // PAY-13 borrower tracker (offer loans)
        'plan_kind',
        'borrower_paid_on',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
            // Platform/originator revenue — separate from `total`, NOT distributed.
            'fees' => 'decimal:2',
            'paid_at' => 'datetime',
            'became_late_at' => 'datetime',
            'days_late' => 'integer',
            'borrower_paid_on' => 'date',
        ];
    }

    /** Legacy per-loan schedule rows only (NULL plan_kind). */
    public function scopeLegacyPlan(Builder $query): Builder
    {
        return $query->whereNull('plan_kind');
    }

    /** Borrower tracking plan rows of an offer loan (PAY-13). */
    public function scopeBorrowerTracker(Builder $query): Builder
    {
        return $query->where('plan_kind', self::PLAN_KIND_BORROWER_TRACKER);
    }

    public function isBorrowerTracker(): bool
    {
        return $this->plan_kind === self::PLAN_KIND_BORROWER_TRACKER;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
