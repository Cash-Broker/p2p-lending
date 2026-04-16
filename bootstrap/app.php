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
