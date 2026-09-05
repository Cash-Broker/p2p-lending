<?php

namespace App\Console\Commands\Loans;

use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\PlatformMetric;
use App\Models\User;
use App\Notifications\LoanPayoutsPausedNotification;
use App\Notifications\PayoutRunFailedAdminNotification;
use App\Notifications\PayoutsPausedAdminNotification;
use App\Services\Loans\PayoutPauseService;
use App\Services\ScheduledPayoutService;
use App\Services\TelegramService;
use App\Support\OpsAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily cron that runs scheduled payouts for every loan in AUTOMATIC payout
 * mode (Loan::payout_mode). Manual-mode loans wait for the admin's button.
 *
 * Accrues/releases on schedule regardless of whether the borrower has paid —
 * the boss's "по график" model; the platform carries the gap in the `accrued`
 * bucket / exposure report.
 *
 * Health: writes to `platform_metrics` (read via /api/health/scheduler —
 * this is the cron that PAYS investors; a silent death here surfaces as
 * angry investors, so it must be as visible as the late/buyback crons):
 *   last_payouts_run_at          — Iso-8601 timestamp
 *   last_payouts_status          — success | failure (failure = some loans errored)
 *   last_payouts_loans_processed — int
 *   last_payouts_loans_failed    — int
 *   last_payouts_loans_paused    — int (PAY-13: loans skipped because their payouts are paused)
 *   last_payouts_pause_newly_paused / last_payouts_pause_resumed — int (PAY-13 reconciler, step 0)
 * The metrics are written whenever a run COMPLETES (even with per-loan
 * failures — the machinery ran; `last_payouts_status` carries the outcome).
 * An uncaught crash writes nothing and the staleness alarm fires instead.
 */
class ProcessScheduledPayouts extends Command
{
    protected $signature = 'loans:process-payouts {--asof= : Process as of this date (Y-m-d), defaults to today}';

    protected $description = 'Run scheduled payouts for loans in automatic payout mode';

    private const LOCK_KEY = 'loans:process-payouts';

    public function handle(ScheduledPayoutService $service, PayoutPauseService $pause): int
    {
        $asOf = $this->option('asof') ? Carbon::parse($this->option('asof')) : now();

        // Interest is never paid into the future. A typo («догони изтървана
        // нощ» with the wrong year) would release every installment up to that
        // date at once — irreversibly, the ledger is immutable and there is no
        // reversal type (audit 2026-09-01, PAY-29). Same rule the early-closure
        // service applies to its as-of date.
        if ($asOf->copy()->startOfDay()->gt(now()->startOfDay())) {
            $this->error("--asof={$asOf->toDateString()} is in the future. Payouts can only be processed up to today.");

            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->error('Another loans:process-payouts instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            // PAY-13 step 0 (owner 2026-09-03): reconcile the payout pause BEFORE
            // paying — here, not in loans:process-late, so a resume never sits
            // behind the late_check_enabled kill switch. Moves no money.
            $pauseResult = ['paused' => [], 'resumed' => [], 'failed' => []];
            try {
                $pauseResult = $pause->reconcile($asOf->copy()->startOfDay()) + $pauseResult;
            } catch (\Throwable $e) {
                // Step 0 moves no money and must never cancel the step that does.
                Log::error('Payout pause reconcile crashed — continuing with the payout run', ['error' => $e->getMessage()]);
                $pauseResult['failed'][] = 0;
            }
            $this->line(sprintf(
                '  payout pause: %d newly paused, %d resumed, %d failed',
                count($pauseResult['paused']),
                count($pauseResult['resumed']),
                count($pauseResult['failed']),
            ));
            if ($pauseResult['failed'] !== []) {
                OpsAlert::mail(
                    'Пауза на авансирането: грешка при съгласуване',
                    sprintf(
                        'loans:process-payouts (%s): съгласуването на паузата не мина за %d кредит(а) (%s). Плащанията продължиха; провери паузите ръчно (loans-process-payouts.log).',
                        $asOf->toDateString(),
                        count($pauseResult['failed']),
                        implode(', ', array_map(fn (int $id) => $id === 0 ? 'целият цикъл' : '#'.$id, $pauseResult['failed'])),
                    ),
                );
            }

            $result = $service->runAllAutomatic($asOf);

            $this->writeMetrics($result, $pauseResult);

            $this->info(sprintf(
                'Scheduled payouts: %d loan(s) processed, %d failed, %d със спряно авансиране (as of %s).',
                $result['loans_processed'],
                $result['loans_failed'],
                $result['loans_paused'] ?? 0,
                $asOf->toDateString(),
            ));

            if ($pauseResult['paused'] !== []) {
                $this->announcePauses($pauseResult['paused'], PayoutPauseService::thresholdDays());
            }

            if ($result['loans_failed'] > 0) {
                Log::warning('loans:process-payouts completed with failures', $result);
                $this->alertAdmins($result);

                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** Upsert the health metrics; measured_at is stamped by PlatformMetric::record. */
    /**
     * Audit 2026-09-01 (A3, PAY-16): a loan that fails inside the run means its
     * investors were NOT paid today while everyone else was. Until now that
     * lived only in a log file — every admin gets mail + bell and Telegram
     * gets a 🔴. Best-effort: alerting never changes the exit code and never
     * touches money already moved.
     */
    private function alertAdmins(array $result): void
    {
        $runAt = now();
        $failedIds = array_values(array_map('intval', $result['failed_loan_ids'] ?? []));
        $idList = $failedIds === [] ? '—' : '#'.implode(', #', $failedIds);

        try {
            foreach (User::where('role', 'admin')->get() as $admin) {
                $admin->notify(new PayoutRunFailedAdminNotification(
                    loansProcessed: (int) $result['loans_processed'],
                    loansFailed: (int) $result['loans_failed'],
                    failedLoanIds: $failedIds,
                    runAt: $runAt,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Payout failure admin notification could not be queued', ['error' => $e->getMessage()]);
        }

        try {
            app(TelegramService::class)->critical(
                'Плащания към инвеститори с грешки',
                sprintf(
                    '%d кредит(а) обработени, %d с грешка: %s. Инвеститорите по тях НЕ са платени днес — виж loans-process-payouts.log.',
                    (int) $result['loans_processed'],
                    (int) $result['loans_failed'],
                    $idList,
                ),
                ['failed_loan_ids' => $idList],
            );
        } catch (\Throwable $e) {
            Log::warning('Payout failure Telegram alert failed', ['error' => $e->getMessage()]);
        }

        // The ops address gets a direct, synchronous mail as well — the admin
        // notifications above ride the queue, which may itself be down (2026-09-03).
        OpsAlert::mail(
            'Плащания към инвеститори с грешки',
            sprintf(
                "Автоматичното плащане от %s: %d кредит(а) обработени, %d с грешка (%s).\nИнвеститорите по тях НЕ са платени днес. Подробности: storage/logs/loans-process-payouts.log.",
                $runAt->format('d.m.Y H:i'),
                (int) $result['loans_processed'],
                (int) $result['loans_failed'],
                $idList,
            ),
        );
    }

    /**
     * PAY-13: a newly paused loan is announced AFTER the run committed — every
     * investor with a pending row in it (mail + inbox, deduped per pause), every
     * admin (mail + bell) and Telegram 🟠. Best-effort: never changes the exit
     * code, never touches money.
     *
     * @param  array<int, int>  $loanIds
     */
    private function announcePauses(array $loanIds, int $thresholdDays): void
    {
        $runAt = now();

        foreach ($loanIds as $loanId) {
            try {
                $loan = Loan::find($loanId);
                if ($loan === null || $loan->payouts_paused_at === null) {
                    continue;
                }
                $daysLate = (int) ($loan->amortizationSchedules()->borrowerTracker()->where('status', 'late')->max('days_late') ?? 0);

                $userIds = Investment::query()
                    ->where('loan_id', $loanId)
                    ->whereHas('schedules', fn ($q) => $q->where('status', 'pending'))
                    ->pluck('user_id')
                    ->unique();

                foreach (User::whereIn('id', $userIds)->get() as $user) {
                    $withheld = InvestmentSchedule::query()
                        ->where('loan_id', $loanId)
                        ->where('status', 'pending')
                        ->whereDate('due_date', '<=', $runAt->toDateString())
                        ->whereIn('investment_id', $loan->investments()->where('user_id', $user->id)->select('id'))
                        ->count();
                    $user->notify(new LoanPayoutsPausedNotification($loanId, $loan->payouts_paused_at, $daysLate, $withheld));
                }
            } catch (\Throwable $e) {
                Log::error('Payout pause investor notification failed', ['loan_id' => $loanId, 'error' => $e->getMessage()]);
            }
        }

        try {
            foreach (User::where('role', 'admin')->get() as $admin) {
                $admin->notify(new PayoutsPausedAdminNotification($loanIds, $runAt, $thresholdDays));
            }
        } catch (\Throwable $e) {
            Log::error('Payout pause admin notification could not be queued', ['error' => $e->getMessage()]);
        }

        try {
            app(TelegramService::class)->high(
                'Спряно авансиране',
                sprintf(
                    'Кредити #%s: кредитополучателят е в закъснение над %d дни. Инвеститорите по тях НЕ се плащат до отбелязване на вноските в „Погасителен план“ или изкупуване.',
                    implode(', #', $loanIds),
                    $thresholdDays,
                ),
                ['loan_ids' => $loanIds],
            );
        } catch (\Throwable $e) {
            Log::warning('Payout pause Telegram alert failed', ['error' => $e->getMessage()]);
        }
    }

    private function writeMetrics(array $result, array $pauseResult = ['paused' => [], 'resumed' => [], 'failed' => []]): void
    {
        $metrics = [
            'last_payouts_run_at' => now()->toIso8601String(),
            'last_payouts_status' => $result['loans_failed'] > 0 ? 'failure' : 'success',
            'last_payouts_loans_processed' => (string) $result['loans_processed'],
            'last_payouts_loans_failed' => (string) $result['loans_failed'],
            // PAY-13
            'last_payouts_loans_paused' => (string) ($result['loans_paused'] ?? 0),
            'last_payouts_pause_newly_paused' => (string) count($pauseResult['paused']),
            'last_payouts_pause_resumed' => (string) count($pauseResult['resumed']),
            'last_payouts_pause_failed' => (string) count($pauseResult['failed'] ?? []),
        ];

        foreach ($metrics as $key => $value) {
            PlatformMetric::record($key, $value);
        }
    }
}
