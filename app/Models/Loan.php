<?php

namespace App\Models;

use App\Enums\PayoutType;
use App\Services\AmortizationService;
use App\Services\APRCalculatorService;
use App\Services\InvestmentScheduleGenerator;
use App\Services\Loans\PayoutPauseService;
use App\Traits\Auditable;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use Auditable, HasFactory;

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const STATUS_FUNDING = 'funding';

    const STATUS_FUNDED = 'funded';

    const STATUS_ACTIVE = 'active';

    const STATUS_LATE = 'late';

    const STATUS_DEFAULT = 'default';

    const STATUS_REPAID = 'repaid';

    const STATUS_BOUGHT_BACK = 'bought_back';

    // Payout trigger mode (boss feature 2026-06-23).
    //   MANUAL    — admin posts each scheduled accrual with a button (current).
    //   AUTOMATIC — a timer accrues each investor's due amount on the schedule
    //               date, regardless of how the loan is serviced.
    // Operational setting — editable after draft (NOT in IMMUTABLE_AFTER_DRAFT)
    // so an admin can switch already-uploaded loans.
    const PAYOUT_MODE_MANUAL = 'manual';

    const PAYOUT_MODE_AUTOMATIC = 'automatic';

    const PAYOUT_MODES = [
        self::PAYOUT_MODE_MANUAL,
        self::PAYOUT_MODE_AUTOMATIC,
    ];

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

    // Statuses the payout engine serves (client decision 2026-08-13, Reni:
    // «след като клиент инвестира, олихвяването си тръгва веднага за него» —
    // interest runs from the INVEST moment, regardless of whether the loan
    // ever reaches 100% funding; a partially funded loan may stay that way).
    // Terminal (repaid/bought_back) and default stay excluded — default is
    // where the open write-off decision lives.
    const PAYOUT_ELIGIBLE_STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_FUNDING,
        self::STATUS_FUNDED,
        self::STATUS_ACTIVE,
        self::STATUS_LATE,
    ];

    // Visibility — orthogonal to status. PRIVATE loans are hidden from the
    // public marketplace and reachable only via their share link (see
    // share_token + loan_grants + LoanPolicy::view).
    const VISIBILITY_PUBLIC = 'public';

    const VISIBILITY_PRIVATE = 'private';

    // Valid state machine transitions — anything not listed here is forbidden.
    // bought_back is TERMINAL per F2 Q3 (investor already paid out; originator's
    // post-buyback collection is off-platform).
    const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PUBLISHED],
        self::STATUS_PUBLISHED => [self::STATUS_DRAFT, self::STATUS_FUNDING],
        // P3-F2 (Phase 3 audit): FUNDING can be abandoned back to DRAFT
        // ONLY when funded_amount == 0. Guards partial investors from
        // being left stranded. The funded_amount check is enforced in
        // the booted() updating hook below, not in canTransitionTo.
        // PAY-30 (owner 2026-09-03): a partially funded loan ends when every
        // investor's own plan has run or was closed early. System-driven only —
        // MANUAL_STATUS_BLOCKLIST keeps `repaid` out of the admin Select.
        self::STATUS_FUNDING => [self::STATUS_FUNDED, self::STATUS_DRAFT, self::STATUS_REPAID],
        self::STATUS_FUNDED => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE => [self::STATUS_LATE, self::STATUS_REPAID],
        self::STATUS_LATE => [self::STATUS_ACTIVE, self::STATUS_DEFAULT, self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_DEFAULT => [self::STATUS_REPAID, self::STATUS_BOUGHT_BACK],
        self::STATUS_REPAID => [],
        self::STATUS_BOUGHT_BACK => [],
    ];

    // ⚠ NO LONGER ENFORCED as a write-guard. Client decision 2026-08-10
    // (Reni, explicit, twice): the admin edits EVERYTHING on a loan at any
    // time, investments or not. What still protects committed investors:
    //   - Investment rows snapshot rate/payout at invest time — their cash
    //     flows and InvestmentContract PDFs NEVER follow later loan edits;
    //   - the quote-vs-commit guard rejects stale-rate commits;
    //   - DB CHECK chk_loans_funded_amount_valid refuses amount < funded;
    //   - status transitions + MANUAL_STATUS_BLOCKLIST still gate money-
    //     moving state changes.
    // The list remains for the Filament warning banner (which fields are
    // contract-sensitive) and for tests/docs grep-ability.
    const IMMUTABLE_AFTER_DRAFT = [
        'amount', 'investable_amount', 'interest_rate', 'interest_rate_annual',
        'term_months', 'originator_id', 'borrower_id', 'co_borrower_id', 'type',
    ];

    // Statuses an admin may NEVER set by hand via the edit-form Select. Each
    // has a dedicated flow that MOVES THE MONEY before stamping the status:
    //   repaid       → scheduled completion / early-repayment action
    //   bought_back  → buyback action
    // Manually picking them would land the loan in a "closed" state while
    // investor principal is still sitting in `invested` — money stranded
    // forever (terminal states accept no further transitions). The activate
    // (funded → active) transition is also excluded from the Select: since
    // 2026-08-13 it happens automatically the moment the loan fills up
    // (InvestmentService::invest), so `funded` is a state no loan rests in
    // and there is nothing left for an admin to activate by hand.
    const MANUAL_STATUS_BLOCKLIST = [
        self::STATUS_REPAID,
        self::STATUS_BOUGHT_BACK,
    ];

    /**
     * Whether the loan's financial/identity terms may still be edited:
     * true until the FIRST investment (no funded money, no investment
     * rows), regardless of draft/published/funding status. From the first
     * invested lev the IMMUTABLE_AFTER_DRAFT fields are frozen — investor
     * contracts snapshot exactly these terms.
     */
    public function isTermsEditable(): bool
    {
        return bccomp((string) ($this->getOriginal('funded_amount') ?? $this->funded_amount ?? '0'), '0', 2) <= 0
            && ! $this->investments()->exists();
    }

    protected static function booted(): void
    {
        static::updating(function (Loan $loan) {
            // Term immutability guard REMOVED per explicit client decision
            // 2026-08-10 — see the IMMUTABLE_AFTER_DRAFT docblock for what
            // still protects committed investors (snapshots, contracts,
            // quote guard, DB CHECKs, status machine).

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
                // published → draft gets the same guard (audit 2026-09-01,
                // PAY-38): an investment committed while the edit form was
                // open moves the loan to funding, and a stale save must not
                // land a loan with money in it back in draft.
                if (in_array($from, [self::STATUS_FUNDING, self::STATUS_PUBLISHED], true)
                    && $to === self::STATUS_DRAFT
                    && bccomp((string) $loan->funded_amount, '0', 2) > 0) {
                    throw new \LogicException(
                        "Cannot abandon loan #{$loan->id} to draft while funded_amount > 0 "
                        ."(current funded_amount = {$loan->funded_amount}). "
                        ."Process investor refunds first — see CLAUDE.md 'Partial-funded abandon' procedure."
                    );
                }

                // PAY-30 (owner 2026-09-03): funding → repaid is reachable only
                // when every investor position is settled — no caller may park
                // principal behind a terminal status.
                if ($from === self::STATUS_FUNDING && $to === self::STATUS_REPAID && $loan->hasOpenInvestorPositions()) {
                    throw new \LogicException(
                        "Cannot close loan #{$loan->id} from funding while investor schedule rows are still open."
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

    /**
     * Statuses the admin may pick in the edit-form Select: the structurally
     * allowed transitions MINUS the money-bearing ones that must run through a
     * payout-performing flow ({@see MANUAL_STATUS_BLOCKLIST}, plus
     * funded → active which must use the activate action so the schedule is
     * generated). The current status is added by the caller as the no-op
     * default. Pure + array-returning so it is unit-testable without Filament.
     *
     * @return list<string>
     */
    /**
     * PAY-30: an offer investment with pending/late rows, or one without any
     * rows at all (pre-2026-08-13 data gap), keeps the loan open. Zero
     * investments ⇒ false (an empty funding loan has nothing to strand).
     */
    public function hasOpenInvestorPositions(): bool
    {
        return $this->investmentSchedules()->whereNotIn('status', ['paid', 'closed'])->exists()
            || $this->investments()
                ->whereNotNull('loan_offer_id')
                ->whereNotExists(fn ($q) => $q->from('investment_schedules')
                    ->whereColumn('investment_schedules.investment_id', 'investments.id'))
                ->exists();
    }

    public function wasClosedWithoutFullFunding(): bool
    {
        return $this->status === self::STATUS_REPAID && $this->closed_from_status === self::STATUS_FUNDING;
    }

    public function selectableStatusTransitions(): array
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return array_values(array_filter($allowed, function (string $target) {
            if (in_array($target, self::MANUAL_STATUS_BLOCKLIST, true)) {
                return false;
            }

            // funded → active must go through the activate action (schedule gen).
            if ($target === self::STATUS_ACTIVE && $this->status === self::STATUS_FUNDED) {
                return false;
            }

            // funding → funded by hand is a dead end (audit 2026-09-01, PAY-36):
            // since 2026-08-13 the last euro activates the loan automatically,
            // `funded` is FUNDABLE no more and has no manual exit — a loan parked
            // there can neither take money nor become active, while the payout
            // engine keeps paying it.
            if ($target === self::STATUS_FUNDED) {
                return false;
            }

            return true;
        }));
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
                    // UNCONDITIONAL since 2026-08-14: generate() is
                    // per-investment idempotent (fills in only investments
                    // without rows). A loan-level exists() guard here would be
                    // true the moment ONE investment has invest-time rows,
                    // silently stranding a pre-change co-investor without a
                    // schedule (adversarial review finding).
                    app(InvestmentScheduleGenerator::class)->generate($this);
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
        // Real credit-contract number, hand-entered by the admin (2026-08-10).
        'contract_number',
        'amount',
        'investable_amount',
        'funded_amount',
        'interest_rate',
        'interest_rate_annual',
        'term_months',
        'type',
        'status',
        'payout_mode',
        'visibility',
        'share_token',
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
        'closed_from_status',
        'closed_at',
        // PAY-13: stamped/cleared only by PayoutPauseService.
        'payouts_paused_at',
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
            'closed_at' => 'datetime',
            'payouts_paused_at' => 'datetime',
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

    /** Investors who were granted access to this private loan via its link. */
    public function grants(): HasMany
    {
        return $this->hasMany(LoanGrant::class);
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
        // PAY-13: borrower tracker rows (plan_kind = borrower_tracker) are a
        // late-detection input on OFFER loans — never a funding cap.
        if ($this->amortizationSchedules()->legacyPlan()->exists()) {
            return $this->amortizationSchedules()
                ->legacyPlan()
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
    /**
     * PAY-13 — the ONE definition of «авансирането е спряно»: the reconciler's
     * stamp AND the setting. Turning the setting off frees the money at the very
     * next payout run without waiting for a reconcile; the engine, the API and
     * Filament all read this.
     */
    public function isPayoutPaused(): bool
    {
        return $this->payouts_paused_at !== null && PayoutPauseService::isEnabled();
    }

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

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /** Generate a fresh, collision-checked share-link token. */
    public static function generateShareToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('share_token', $token)->exists());

        return $token;
    }

    /** Grant an investor access to this (private) loan — idempotent. */
    public function grantAccessTo(User $user): LoanGrant
    {
        return $this->grants()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Whether $user may view this loan. Public loans (and admins) are always
     * visible; a private loan is visible only to an investor who holds a
     * position in it OR was granted access via its link. Single source of
     * truth for LoanPolicy::view + the marketplace/favorites filters.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $this->isPrivate()) {
            return true;
        }

        return $this->investments()->where('user_id', $user->id)->exists()
            || $this->grants()->where('user_id', $user->id)->exists();
    }
}
