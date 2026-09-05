<?php

use App\Http\Middleware\AttachBuildVersion;
use App\Http\Middleware\EnsureConsentsCurrent;
use App\Http\Middleware\EnsureIsInvestor;
use App\Http\Middleware\EnsureKycApproved;
use App\Http\Middleware\SecurityHeaders;
use App\Services\TelegramService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Spatie\Csp\AddCspHeaders;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    // Event listeners are wired ONLY by the explicit Event::listen calls in
    // AppServiceProvider::boot() — see the note there. Laravel's automatic
    // discovery of app/Listeners is off, because with it on every listener
    // was registered TWICE (discovery + the explicit call) and every admin
    // login fired two identical Telegram alerts (found 2026-08-20). One
    // mechanism, greppable, pinned by EventListenerRegistrationTest.
    ->withEvents(discover: false)
    ->withSchedule(function (Schedule $schedule): void {
        // Every cron failure reaches the ops address — «ако някоя операция не
        // мине» (owner 2026-09-03). Output capture is already on for each command.
        $opsEmail = (string) config('app.admin_email');

        // Ledger reconciliation — daily at 03:00, sends email alert on mismatch
        $schedule->command('ledger:reconcile --notify')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/ledger-reconcile.log'))
            ->emailOutputOnFailure($opsEmail);

        // Late-loan automation — daily at 03:30, after ledger reconciliation
        // has finished and the DB is in a clean state. The 60-minute lock TTL
        // matches a typical worst-case run duration; longer than the run
        // would take in production but short enough that a stale lock from a
        // crashed process clears before the next day's run.
        // Output appended to its own log file so support can tail it
        // separately and apply its own log-rotation rules.
        $schedule->command('loans:process-late')
            ->dailyAt('03:30')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/loans-process-late.log'))
            ->emailOutputOnFailure($opsEmail);

        // F2 — buyback-eligibility detection. Daily at 03:45, after the
        // late-detection cron has transitioned loans into/out of 'late'
        // status. Buyback detection READS loan.status, so it must run
        // AFTER late detection.
        //
        // 15-minute gap: empirically F1 runs in << 5 min; 15 min is a
        // comfortable safety buffer. If F1 ever exceeds 15 min consistently
        // (growth, slow queue), move F2 to 04:00 — flag in CLAUDE.md.
        //
        // Separate log file — ops can tail independently.
        $schedule->command('loans:detect-buyback-eligible')
            ->dailyAt('03:45')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/loans-detect-buyback-eligible.log'))
            ->emailOutputOnFailure($opsEmail);

        // Scheduled payouts — daily at 04:00, after late-detection has settled
        // loan statuses. Accrues/releases each investor's due amount per their
        // plan for loans in AUTOMATIC payout mode (manual loans wait for the
        // admin button). Own log file for independent tailing.
        $schedule->command('loans:process-payouts')
            ->dailyAt('04:00')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/loans-process-payouts.log'))
            ->emailOutputOnFailure($opsEmail);

        // Conditional bonuses — release the ones whose investment condition is
        // now met (Reni 2026-08-18). Runs 15 minutes AFTER the payout cron on
        // purpose: that run is what marks installments paid, so a bonus whose
        // third payout lands tonight is free the same night. Idempotent — a
        // re-run releases nothing twice.
        $schedule->command('bonuses:release-eligible')
            ->dailyAt('04:15')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/bonuses-release-eligible.log'))
            ->emailOutputOnFailure($opsEmail);

        // SEC-22 (owner 2026-09-03): finalise confirmed account closures whose
        // waiting period ran out — after every money cron so the ledger is quiet
        // when a wallet row disappears. Kill switch account_deletion_finalize_enabled.
        $schedule->command('accounts:finalize-deletions')
            ->dailyAt('04:30')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/accounts-finalize-deletions.log'))
            ->emailOutputOnFailure($opsEmail);

        // SEC-16: purge KYC archives whose ЗМИП clock ran out. Kill switch kyc_retention_purge_enabled.
        $schedule->command('kyc:purge-retained')
            ->dailyAt('05:00')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/kyc-purge-retained.log'))
            ->emailOutputOnFailure($opsEmail);

        // Daily morning digest — Telegram summary (INFO tier, silent) of
        // platform state: new registrations, KYC pending, deposits awaiting
        // confirmation, withdrawals awaiting processing, buyback queue size,
        // late loan count. Additionally EMAILS admin accounts when at least
        // one actionable item is pending (no email on all-clear mornings).
        // Runs at 09:00 in app timezone (Europe/Sofia per .env). If
        // TELEGRAM_BOT_TOKEN is not configured only the Telegram part is
        // skipped — the admin email still goes out.
        $schedule->command('telegram:digest')
            ->dailyAt('09:00')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/telegram-digest.log'))
            ->emailOutputOnFailure($opsEmail);

        // Investor weekly earnings bulletin (Reni 2026-08-13) — Monday
        // mornings after the admin digest. Queued mails; investors with
        // nothing received and nothing accruing are skipped. Kill switch:
        // investor_weekly_email_enabled platform setting.
        $schedule->command('investors:weekly-earnings')
            ->weeklyOn(1, '09:30')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/investors-weekly-earnings.log'))
            ->emailOutputOnFailure($opsEmail);

        // Morning Web Push digest (2026-08-17): the night's 04:00 payout run
        // batched into one «получихте X € лихва» push per subscribed
        // investor — pushing at 04:00 would wake people. Kill switch:
        // push_payout_digest_enabled platform setting.
        $schedule->command('push:payout-digest')
            ->dailyAt('09:05')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/push-payout-digest.log'))
            ->emailOutputOnFailure($opsEmail);

        // Audit 2026-09-01 (A3): every admin alert and investor mail is queued,
        // so a stopped worker fails silently. queue:monitor raises QueueBusy
        // (→ TelegramQueueBusyAlert) once the backlog passes the threshold.
        $schedule->command('queue:monitor database:default --max=100')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->emailOutputOnFailure($opsEmail);
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        // Every response carries the frontend build fingerprint so stale SPA
        // tabs self-reload after a deploy (see AttachBuildVersion).
        $middleware->append(AttachBuildVersion::class);
        // Spatie CSP — currently emits Report-Only header (config/csp.php).
        // Promote App\Support\CspPolicy to `presets` for enforcement once
        // production violations are clean.
        $middleware->append(AddCspHeaders::class);
        $middleware->alias([
            'investor' => EnsureIsInvestor::class,
            'kyc' => EnsureKycApproved::class,
            'consent.current' => EnsureConsentsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mirror uncaught exceptions to Telegram (CRITICAL tier).
        // Skipped for HTTP 4xx (validation, auth errors etc.) — these are
        // expected and would cause noise. Only 5xx-class server errors land
        // in Telegram.
        $exceptions->reportable(function (Throwable $e) {
            if ($e instanceof ValidationException) {
                return;
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if ($status >= 400 && $status < 500) {
                    return;
                }
            }
            try {
                $svc = app(TelegramService::class);
                if (! $svc->isConfigured()) {
                    return;
                }
                $request = request();
                $url = $request ? ($request->method().' '.$request->fullUrl()) : 'CLI / queue';
                $userId = optional(auth()->user())->id ?? '(none)';
                // A failed SQL statement is echoed with its bound values (names,
                // IBANs, amounts) — Telegram gets the class and location only;
                // the full text stays in the application log.
                $summary = $e instanceof QueryException
                    ? get_class($e).' (SQL details withheld — see the application log)'
                    : get_class($e).': '.$e->getMessage();
                $svc->critical(
                    'Production Exception',
                    mb_substr($summary, 0, 800),
                    [
                        'url' => mb_substr($url, 0, 200),
                        'user_id' => $userId,
                        'file' => basename($e->getFile()).':'.$e->getLine(),
                    ],
                );
            } catch (Throwable $ignored) {
                // Telegram dispatch must never break the original error flow.
            }
        });
    })->create();
