<?php

namespace App\Models;

use App\Enums\PayoutType;
use App\Services\AmortizationService;
use App\Services\APRCalculatorService;
use App\Services\InvestmentScheduleGenerator;
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
    // default is visible (Phase 3 P3-F4 fix) — investors holding a position
    // in a loan that reaches DEFAULT must be able to see its detail page.
    // Previously they'd see the loan in /api/portfolio (not status-filtered)
    // but get 403 on /api/loans/{id}. Confusing UX, no security reason to
    // hide the status from the holding investor.
    const INVESTOR_VISIBLE_STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_FUNDING,
        self::STATUS_FUNDED,
        self::STATUS_ACTIVE,
        self::STATUS_LATE,
        self::STATUS_DEFAULT,
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
        // P3-F2 (Phase 3 audit): FUNDING can be abandoned back to DRAFT
        // ONLY when funded_amount == 0. Guards partial investors from
        // being left stranded. The funded_amount check is enforced in
        // the booted() updating hook below, not in canTransitionTo.
        self::STATUS_FUNDING   => [self::STATUS_FUNDED, self::STATUS_DRAFT],
        self::STATUS_FUNDED    => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE    => [self::STATUS_LATE, self::STATUS_REPAID],
        self::STATUS_LATE      => [self::STATUS_ACTIVE, self::STATUS_DEFAULT, self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_DEFAULT   => [self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_REPAID    => [],
        self::STATUS_BOUGHT_BACK => [],
    ];

    // Fields that become immutable once the loan leaves draft status
    const IMMUTABLE_AFTER_DRAFT = [
        'amount', 'investable_amount', 'interest_rate', 'interest_rate_annual',
        'term_months', 'originator_id', 'borrower_id', 'co_borrower_id', 'type',
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

                // P3-F2: funding → draft requires zero funded_amount.
                // The ALLOWED_TRANSITIONS entry permits the structural
                // transition; this guard enforces the business rule that
                // partial investors MUST NOT be stranded by an abandon.
                // If funded_amount > 0, the admin must process refunds
                // (manual compensating transactions) before resetting.
                if ($from === self::STATUS_FUNDING
                    && $to === self::STATUS_DRAFT
                    && bccomp((string) $loan->funded_amount, '0', 2) > 0) {
                    throw new \LogicException(
                        "Cannot abandon loan #{$loan->id} to draft while funded_amount > 0 "
                        . "(current funded_amount = {$loan->funded_amount}). "
                        . "Process investor refunds first — see CLAUDE.md 'Partial-funded abandon' procedure."
                    );
                }
            }
        });

        // Every new loan is seeded with the three default investor offers
        // (12/16/20). The boss then freely edits rates / toggles availability
        // per loan. Seeded through the relation so LoanOffer model events
        // (audit) fire; the backfill migration uses raw inserts for pre-existing
        // loans. Unique(loan_id, payout_type) guards against any double-seed.
        static::created(function (Loan $loan) {
            $offers = [];
            foreach (PayoutType::defaults() as $type) {
                $offers[] = [
                    'payout_type' => $type->value,
                    'interest_rate' => $type->defaultRate(),
                    'is_enabled' => true,
                    'position' => $type->position(),
                ];
            }
            $loan->offers()->createMany($offers);
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

            // On first activation (funded → active), auto-generate the payout
            // schedule. Two paths:
            //   • offer-based loans → per-investment investment_schedules, each
            //     honouring its own offer (amortizing / interest-only /
            //     capitalized). This is the 3-offer feature's disbursement basis.
            //   • legacy loans → the single per-loan amortization schedule, via
            //     AmortizationService — UNCHANGED, byte-identical to before.
            // Both skip-if-exists so a pre-generated schedule (admin calculator)
            // or a LATE → ACTIVE return doesn't regenerate / throw.
            if ($fromStatus === self::STATUS_FUNDED && $newStatus === self::STATUS_ACTIVE) {
                if ($this->usesOffers()) {
                    if (! $this->investmentSchedules()->exists()) {
                        app(InvestmentScheduleGenerator::class)->generate($this);
                    }
                } elseif (! $this->amortizationSchedules()->exists()) {
                    app(AmortizationService::class)->generateSchedule($this);
                }
            }
        });
    }

    protected $fillable = [
        'originator_id',
        'borrower_id',
        'co_borrower_id',
        'amount',
        'investable_amount',
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
            'investable_amount' => 'decimal:2',
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

    /**
     * Per-instance memoization for apr(). Separate "resolved" flag is
     * needed because null is a valid cached outcome (F1-L6 fallback).
     */
    protected ?string $aprMemo = null;
    protected bool $aprMemoResolved = false;

    /**
     * F5 — Annual Percentage Rate / Годишен Процент на Разходите.
     *
     * Delegates to {@see APRCalculatorService}. Memoized per-instance
     * because the service may become expensive (IRR Newton-Raphson)
     * when borrower-side fees activate. Today it's a one-liner, but
     * the cache costs nothing and future-proofs the contract.
     *
     * Returns null → UI should render "—" (dash), NOT "0.00%".
     */
    public function apr(): ?string
    {
        if ($this->aprMemoResolved) {
            return $this->aprMemo;
        }

        $this->aprMemo = app(APRCalculatorService::class)->calculate($this);
        $this->aprMemoResolved = true;
        return $this->aprMemo;
    }

    public function originator(): BelongsTo
    {
        return $this->belongsTo(Originator::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function coBorrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'co_borrower_id');
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

    /**
     * Anonymized profile of the co-debtor (съдлъжник), surfaced to investors
     * alongside the primary borrower's. Null when the loan has no co-debtor.
     */
    public function coBorrowerAnonymizedProfile(): HasOneThrough
    {
        return $this->hasOneThrough(
            BorrowerAnonymizedProfile::class,
            Borrower::class,
            'id',              // borrowers.id
            'borrower_id',     // borrower_anonymized_profiles.borrower_id
            'co_borrower_id',  // loans.co_borrower_id
            'id'               // borrowers.id
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

    /** The (up to three) investor offers on this loan. */
    public function offers(): HasMany
    {
        return $this->hasMany(LoanOffer::class)->orderBy('position');
    }

    /** Per-investment payout schedules (offer-based loans only). */
    public function investmentSchedules(): HasMany
    {
        return $this->hasMany(InvestmentSchedule::class);
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
        return bccomp($this->funded_amount, $this->fundingCap(), 2) >= 0;
    }

    /**
     * How much investors can still fund toward this loan.
     *
     * Normally the investable amount. But when a back-dated schedule was
     * generated up-front — with elapsed installments pre-marked paid — investors
     * fund only the OUTSTANDING (unpaid) principal, so they fund exactly what
     * they will be repaid (no over-funding against already-settled installments).
     * Returned as a bcmath-safe string.
     */
    public function fundingCap(): string
    {
        if ($this->amortizationSchedules()->exists()) {
            return $this->amortizationSchedules()
                ->whereIn('status', ['pending', 'late'])
                ->get(['principal'])
                ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');
        }

        return $this->investableAmount();
    }

    /**
     * How much of the loan is offered to platform investors. Falls back to the
     * full `amount` when `investable_amount` is unset (in-flight loans, audit
     * fixtures) so behavior is identical to before the cap was introduced.
     * Returned as a bcmath-safe string.
     */
    public function investableAmount(): string
    {
        return (string) ($this->investable_amount ?? $this->amount);
    }

    /**
     * The principal total that the amortization schedule must sum to.
     *
     * Per the client decision, the on-platform schedule amortizes the INVESTABLE
     * portion (not the full loan amount), so investors are repaid exactly the
     * capital they invested. Centralised so every Σ-principal check (the
     * relation-manager balancing guard, AmortizationService) shares one source of
     * truth. When investable_amount is null it equals `amount` — keeping every
     * existing test/fixture unchanged.
     */
    public function amortizationBase(): string
    {
        return $this->investableAmount();
    }

    /**
     * Whether any investment in this loan was committed under a 3-offer payout
     * structure. This is the switch that routes disbursement: offer-based loans
     * use per-investment investment_schedules; legacy loans (every investment
     * has loan_offer_id = null) keep the per-loan amortization schedule + the
     * existing pro-rata RepaymentService, byte-identical to before the feature.
     */
    public function usesOffers(): bool
    {
        return $this->investments()->whereNotNull('loan_offer_id')->exists();
    }

    /**
     * [min, max] annual rate across this loan's ENABLED offers, formatted to 2
     * decimals, or null when none are enabled. Drives the marketplace
     * "от X% до Y%" badge. Compares numerically (decimal strings sort wrong
     * lexicographically — '9.00' vs '12.00').
     *
     * @return array{0: string, 1: string}|null
     */
    public function offerRateRange(): ?array
    {
        $rates = $this->offers
            ->where('is_enabled', true)
            ->pluck('interest_rate')
            ->map(fn ($r) => (float) $r);

        if ($rates->isEmpty()) {
            return null;
        }

        return [
            number_format($rates->min(), 2, '.', ''),
            number_format($rates->max(), 2, '.', ''),
        ];
    }
}
