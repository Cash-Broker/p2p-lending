<?php

namespace App\Models;

use App\Services\AmortizationService;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory, \App\Traits\Auditable;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_FUNDING = 'funding';
    const STATUS_FUNDED = 'funded';
    const STATUS_ACTIVE = 'active';
    const STATUS_LATE = 'late';
    const STATUS_DEFAULT = 'default';
    const STATUS_REPAID = 'repaid';
    const STATUS_BOUGHT_BACK = 'bought_back';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_FUNDING,
        self::STATUS_FUNDED,
        self::STATUS_ACTIVE,
        self::STATUS_LATE,
        self::STATUS_DEFAULT,
        self::STATUS_REPAID,
        self::STATUS_BOUGHT_BACK,
    ];

    // Statuses visible to investors (excludes draft).
    // bought_back is visible so investors see the final outcome of a loan
    // that was taken over by the originator.
    const INVESTOR_VISIBLE_STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_FUNDING,
        self::STATUS_FUNDED,
        self::STATUS_ACTIVE,
        self::STATUS_LATE,
        self::STATUS_REPAID,
        self::STATUS_BOUGHT_BACK,
    ];

    // Statuses where investment is possible
    const FUNDABLE_STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_FUNDING,
    ];

    // Valid state machine transitions — anything not listed here is forbidden.
    // bought_back is TERMINAL per F2 Q3 (investor already paid out; originator's
    // post-buyback collection is off-platform).
    const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_PUBLISHED],
        self::STATUS_PUBLISHED => [self::STATUS_DRAFT, self::STATUS_FUNDING],
        self::STATUS_FUNDING   => [self::STATUS_FUNDED],
        self::STATUS_FUNDED    => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE    => [self::STATUS_LATE, self::STATUS_REPAID],
        self::STATUS_LATE      => [self::STATUS_ACTIVE, self::STATUS_DEFAULT, self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_DEFAULT   => [self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_REPAID    => [],
        self::STATUS_BOUGHT_BACK => [],
    ];

    // Fields that become immutable once the loan leaves draft status
    const IMMUTABLE_AFTER_DRAFT = [
        'amount', 'interest_rate', 'interest_rate_annual',
        'term_months', 'originator_id', 'borrower_id', 'type',
    ];

    protected static function booted(): void
    {
        static::updating(function (Loan $loan) {
            // Enforce term immutability after draft
            if ($loan->getOriginal('status') !== self::STATUS_DRAFT) {
                foreach (self::IMMUTABLE_AFTER_DRAFT as $field) {
                    if ($loan->isDirty($field)) {
                        throw new \LogicException("Cannot modify '{$field}' on a non-draft loan.");
                    }
                }
            }

            // Enforce valid status transitions
            if ($loan->isDirty('status')) {
                $from = $loan->getOriginal('status');
                $to = $loan->status;
                if (! $loan->canTransitionTo($to, $from)) {
                    throw new \LogicException("Invalid loan status transition: {$from} → {$to}.");
                }
            }
        });
    }

    public function canTransitionTo(string $newStatus, ?string $fromStatus = null): bool
    {
        $from = $fromStatus ?? $this->getOriginal('status') ?? $this->status;
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        return in_array($newStatus, $allowed);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            $from = $this->getOriginal('status') ?? $this->status;
            throw new \InvalidArgumentException("Invalid loan status transition: {$from} → {$newStatus}.");
        }

        $fromStatus = $this->getOriginal('status') ?? $this->status;

        // Wrap the status change and any side effects (e.g. schedule generation)
        // in one transaction so they commit or roll back together.
        DB::transaction(function () use ($newStatus, $fromStatus) {
            $this->forceFill(['status' => $newStatus])->save();

            // On first activation (funded → active), auto-generate the amortization
            // schedule. LATE → ACTIVE is a return from delinquency; schedule already
            // exists from the original activation.
            if ($fromStatus === self::STATUS_FUNDED && $newStatus === self::STATUS_ACTIVE) {
                app(AmortizationService::class)->generateSchedule($this);
            }
        });
    }

    protected $fillable = [
        'originator_id',
        'borrower_id',
        'amount',
        'funded_amount',
        'interest_rate',
        'interest_rate_annual',
        'term_months',
        'type',
        'status',
        'published_at',
        'last_late_check_at',
        'became_late_at',
        'buyback_eligible_at',
        'bought_back_at',
        'buyback_dismissed_at',
        'buyback_dismissed_reason',
        'buyback_dismissed_by',
        'early_repaid_at',
        'early_repayment_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'funded_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_rate_annual' => 'decimal:2',
            'published_at' => 'datetime',
            'last_late_check_at' => 'datetime',
            'became_late_at' => 'datetime',
            'buyback_eligible_at' => 'datetime',
            'bought_back_at' => 'datetime',
            'buyback_dismissed_at' => 'datetime',
            'early_repaid_at' => 'datetime',
            'early_repayment_amount' => 'decimal:2',
        ];
    }

    public function originator(): BelongsTo
    {
        return $this->belongsTo(Originator::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function anonymizedProfile(): HasOneThrough
    {
        return $this->hasOneThrough(
            BorrowerAnonymizedProfile::class,
            Borrower::class,
            'id',           // borrowers.id
            'borrower_id',  // borrower_anonymized_profiles.borrower_id
            'borrower_id',  // loans.borrower_id
            'id'            // borrowers.id
        );
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LoanEvent::class);
    }

    public function isFundable(): bool
    {
        return in_array($this->status, self::FUNDABLE_STATUSES);
    }

    public function isFullyFunded(): bool
    {
        return bccomp($this->funded_amount, $this->amount, 2) >= 0;
    }
}
