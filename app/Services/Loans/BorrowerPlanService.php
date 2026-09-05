<?php

namespace App\Services\Loans;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Services\TelegramService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The BORROWER TRACKING PLAN of an OFFER loan — PAY-13 (owner 2026-09-03).
 *
 * Offer loans pay their investors on schedule regardless of the borrower
 * (PayoutAccrualService), so the investors' rows can never carry lateness.
 * The only place the fact «кредитополучателят плати / не плати» can live is a
 * per-loan plan in `amortization_schedules` with `plan_kind = 'borrower_tracker'`
 * — the one table the F1/F2 machinery (LateDetectionService,
 * LoanStatusUpdaterService, BuybackEligibilityService) already reads. That is
 * how an offer loan now becomes `late`, recovers and enters the Buyback Queue.
 *
 *   • Dates are what matter. Amounts are a LINEAR split (equal principal, last
 *     row absorbs the remainder — Σ principal == amortizationBase(), never
 *     negative) plus informational interest at the borrower's annual rate.
 *     Nothing here is ever distributed: RepaymentService refuses offer loans
 *     (PAY-25) and every consumer that must not see tracker rows uses the
 *     `legacyPlan()` scope.
 *   • The admin attests each borrower installment (recordBorrowerPayment /
 *     markPaidThrough): status → paid, `paid_at = now()` so recovery rule R1
 *     recognises a genuine recovery, the attested date in `borrower_paid_on`,
 *     the admin id in `recorded_by`. ZERO WalletService calls, ZERO Transaction
 *     rows — the architecture test enforces it.
 *   • Created by the admin from the loan's «Погасителен план» tab (first-due +
 *     «платени до» dates) or — ONLY with `borrower_tracker_auto_generate` on —
 *     after commit at activation. The setting ships OFF (owner 2026-09-05).
 */
class BorrowerPlanService
{
    /** Rate-math scale — matches OfferProjectionService / AmortizationService. */
    private const RATE_SCALE = 10;

    /** Attestation dates are calendar days in Sofia — the app clock is UTC on prod. */
    public const BUSINESS_TZ = 'Europe/Sofia';

    public const SETTING_AUTO_GENERATE = 'borrower_tracker_auto_generate';

    /**
     * Owner 2026-09-05: Reni will not record borrower installments by hand, so no
     * tracker may appear on its own. FALSE until an automation path is chosen
     * (bank-statement import or an exception-driven «длъжникът не плати» flag).
     */
    public static function autoGenerateEnabled(): bool
    {
        return (bool) PlatformSetting::get(self::SETTING_AUTO_GENERATE, false);
    }

    public function __construct(private TelegramService $telegram) {}

    /**
     * Create the tracker for a live offer loan. Refuses legacy loans, fundable
     * loans (funding-stage coverage is an open owner question) and loans that
     * already have a tracker — never overwrites.
     *
     * @return int rows created
     *
     * @throws InvalidArgumentException
     */
    public function generate(Loan $loan, CarbonInterface $firstDue, ?CarbonInterface $paidThrough, ?int $adminId): int
    {
        return DB::transaction(function () use ($loan, $firstDue, $paidThrough, $adminId): int {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $this->assertTrackable($locked);

            if ($locked->amortizationSchedules()->borrowerTracker()->exists()) {
                throw new InvalidArgumentException('Кредитът вече има план на кредитополучателя.');
            }

            $rows = $this->buildRows($locked, $firstDue, $paidThrough);
            foreach ($rows as $row) {
                AmortizationSchedule::create([
                    'loan_id' => $locked->id,
                    'plan_kind' => AmortizationSchedule::PLAN_KIND_BORROWER_TRACKER,
                    'due_date' => $row['due_date'],
                    'principal' => $row['principal'],
                    'interest' => $row['interest'],
                    'total' => $row['total'],
                    'fees' => '0.00',
                    'status' => $row['paid'] ? 'paid' : 'pending',
                    'paid_at' => $row['paid'] ? now() : null,
                    'borrower_paid_on' => $row['paid'] ? $row['due_date'] : null,
                    'recorded_by' => $row['paid'] ? $adminId : null,
                ]);
            }

            return count($rows);
        });
    }

    /**
     * Pure preview: how many rows the plan would create as unpaid AND already
     * past the grace period — the admin must acknowledge those before creating
     * a tracker that makes the loan `late` the same night.
     */
    public function overduePreview(Loan $loan, CarbonInterface $firstDue, ?CarbonInterface $paidThrough, ?CarbonInterface $today = null): int
    {
        $grace = (int) PlatformSetting::get('grace_period_days', 10);
        $threshold = CarbonImmutable::instance($today ?? now())->startOfDay()->subDays($grace);

        $overdue = 0;
        foreach ($this->buildRows($loan, $firstDue, $paidThrough) as $row) {
            if (! $row['paid'] && CarbonImmutable::parse($row['due_date'])->lessThanOrEqualTo($threshold)) {
                $overdue++;
            }
        }

        return $overdue;
    }

    /**
     * Runs from DB::afterCommit in InvestmentService::invest() — the invest is
     * already committed, so this NEVER throws: a failure is logged + Telegram
     * and the digest counts «активни офертни кредити без план» until the admin
     * creates the tracker by hand.
     */
    public function generateAtActivation(int $loanId): void
    {
        if (! self::autoGenerateEnabled()) {
            return; // dormant by default — see autoGenerateEnabled()
        }

        try {
            $loan = Loan::find($loanId);
            if ($loan === null || ! $loan->usesOffers() || $loan->status !== Loan::STATUS_ACTIVE) {
                return;
            }
            if ($loan->amortizationSchedules()->borrowerTracker()->exists()) {
                return;
            }

            $this->generate($loan, today()->addMonthsNoOverflow(1), null, null);
        } catch (Throwable $e) {
            Log::error('Borrower tracking plan was not generated at activation', [
                'loan_id' => $loanId,
                'error' => $e->getMessage(),
            ]);
            try {
                $this->telegram->high(
                    'Планът на кредитополучателя не е генериран',
                    "Кредит #{$loanId} е активен без план за проследяване на кредитополучателя — създай го от таб „Погасителен план“. Грешка: {$e->getMessage()}",
                    ['loan_id' => $loanId],
                );
            } catch (Throwable) {
                // best-effort
            }
        }
    }

    /**
     * Attest one borrower installment. Records the FACT only — no money moves.
     *
     * @throws InvalidArgumentException
     */
    public function recordBorrowerPayment(int $loanId, int $rowId, CarbonInterface $paidOn, int $adminId): AmortizationSchedule
    {
        return DB::transaction(function () use ($loanId, $rowId, $paidOn, $adminId): AmortizationSchedule {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();
            if (! $loan->usesOffers()) {
                throw new InvalidArgumentException('Легаси кредит: вноските се осчетоводяват през „Погашения“.');
            }

            $row = AmortizationSchedule::whereKey($rowId)->where('loan_id', $loanId)->lockForUpdate()->firstOrFail();
            if (! $row->isBorrowerTracker()) {
                throw new InvalidArgumentException('Редът не е част от плана на кредитополучателя.');
            }
            if (! in_array($row->status, ['pending', 'late'], true)) {
                throw new InvalidArgumentException('Вноската вече е отбелязана.');
            }

            $paidOnDay = CarbonImmutable::instance($paidOn)->startOfDay();
            if ($paidOnDay->toDateString() > CarbonImmutable::now(self::BUSINESS_TZ)->toDateString()) {
                throw new InvalidArgumentException('Датата на плащане не може да е в бъдещето.');
            }

            // paid_at = now() on purpose: recovery rule R1 needs paid_at ≥ became_late_at.
            $row->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'borrower_paid_on' => $paidOnDay->toDateString(),
                'recorded_by' => $adminId,
            ])->save();

            return $row;
        });
    }

    /**
     * Attest every unpaid tracker row due on or before $through.
     *
     * @return int rows marked
     *
     * @throws InvalidArgumentException
     */
    public function markPaidThrough(int $loanId, CarbonInterface $through, int $adminId): int
    {
        return DB::transaction(function () use ($loanId, $through, $adminId): int {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();
            if (! $loan->usesOffers()) {
                throw new InvalidArgumentException('Легаси кредит: вноските се осчетоводяват през „Погашения“.');
            }

            $throughDay = CarbonImmutable::instance($through)->startOfDay();
            if ($throughDay->toDateString() > CarbonImmutable::now(self::BUSINESS_TZ)->toDateString()) {
                throw new InvalidArgumentException('Датата не може да е в бъдещето.');
            }

            $rows = $loan->amortizationSchedules()
                ->borrowerTracker()
                ->whereIn('status', ['pending', 'late'])
                ->whereDate('due_date', '<=', $throughDay->toDateString())
                ->lockForUpdate()
                ->orderBy('due_date')
                ->get();

            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'borrower_paid_on' => $row->due_date->toDateString(),
                    'recorded_by' => $adminId,
                ])->save();
            }

            return $rows->count();
        });
    }

    /**
     * The linear plan, pure and bcmath-only. Σ principal == amortizationBase()
     * exactly (last row absorbs the rounding remainder); no row can be negative.
     *
     * @return array<int, array{due_date: string, principal: string, interest: string, total: string, paid: bool}>
     */
    public function buildRows(Loan $loan, CarbonInterface $firstDue, ?CarbonInterface $paidThrough): array
    {
        $n = (int) $loan->term_months;
        if ($n <= 0) {
            throw new InvalidArgumentException('Кредитът няма срок в месеци.');
        }

        $base = bcadd((string) $loan->amortizationBase(), '0', 2);
        $rate = (string) ($loan->interest_rate_annual ?? $loan->interest_rate ?? '0');
        $monthlyRate = bcdiv(bcdiv($rate, '100', self::RATE_SCALE), '12', self::RATE_SCALE);
        $each = bcdiv($base, (string) $n, 2);

        $first = CarbonImmutable::instance($firstDue)->startOfDay();
        $through = $paidThrough !== null ? CarbonImmutable::instance($paidThrough)->startOfDay() : null;

        $rows = [];
        $remaining = $base;
        for ($i = 0; $i < $n; $i++) {
            $principal = $i === $n - 1 ? $remaining : $each;
            $interest = bcmul($remaining, $monthlyRate, 2);
            $due = $first->addMonthsNoOverflow($i);

            $rows[] = [
                'due_date' => $due->toDateString(),
                'principal' => $principal,
                'interest' => $interest,
                'total' => bcadd($principal, $interest, 2),
                'paid' => $through !== null && $due->lessThanOrEqualTo($through),
            ];

            $remaining = bcsub($remaining, $principal, 2);
        }

        return $rows;
    }

    private function assertTrackable(Loan $loan): void
    {
        if (! $loan->usesOffers()) {
            throw new InvalidArgumentException('Легаси кредит: погасителният план се смята с калкулатора, а вноските се осчетоводяват през „Погашения“.');
        }
        if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_LATE], true)) {
            throw new InvalidArgumentException('План на кредитополучателя се създава само за активен или закъснял кредит.');
        }
        if ((int) $loan->term_months <= 0) {
            throw new InvalidArgumentException('Кредитът няма срок в месеци.');
        }
    }
}
