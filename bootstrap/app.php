<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(function (Schedule $schedule): void {
        // Ledger reconciliation — daily at 03:00, sends email alert on mismatch
        $schedule->command('ledger:reconcile --notify')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground();

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
            ->appendOutputTo(storage_path('logs/loans-process-late.log'));
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Spatie CSP — currently emits Report-Only header (config/csp.php).
        // Promote App\Support\CspPolicy to `presets` for enforcement once
        // production violations are clean.
        $middleware->append(\Spatie\Csp\AddCspHeaders::class);
        $middleware->alias([
            'investor' => \App\Http\Middleware\EnsureIsInvestor::class,
            'kyc' => \App\Http\Middleware\EnsureKycApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
