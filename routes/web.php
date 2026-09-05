<?php

use App\Http\Controllers\AccountDeletionLinkController;
use App\Http\Controllers\SavedIbanConfirmationController;
use App\Models\AdminTrustedIp;
use App\Models\AuditLog;
use App\Models\Investment;
use App\Models\InvestmentContract;
use App\Models\KycRetention;
use App\Models\User;
use App\Services\InvestmentContractService;
use App\Services\KycRetentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Stub `login` named route — Laravel's web `auth` middleware calls
// `route('login')` to redirect unauthenticated users; without this stub
// any unauthenticated request to a `web`+`auth` URL throws
// RouteNotFoundException → 500. The Vue SPA's own router will render the
// login page from the `app.blade.php` shell.
Route::get('/login', fn () => view('app'))->name('login');

// Stub `password.reset` named route — Laravel's default ResetPassword
// notification calls `route('password.reset', ['token' => ..., 'email' => ...])`
// to build the email link. Without this stub, every queued password-reset
// dispatch throws RouteNotFoundException — surfaced via the Telegram
// exception reporter as a CRITICAL on every forgot-password attempt.
//
// The path matches the Vue SPA's router (`/reset-password/:token`); Vue
// reads the token from the path and the email from the query string,
// then POSTs to /api/reset-password.
Route::get('/reset-password/{token}', fn () => view('app'))->name('password.reset');

// Mark an IP as trusted for an admin user. Reached from the "trust this IP"
// link in the admin login alert email. Signed URL gates access — the link is
// authentication-equivalent, expires in 7 days, and can be sent only to the
// admin who actually received the alert.
Route::get('/admin/trust-ip/{user}/{ip}', function (Request $request, int $user, string $ip) {
    $admin = User::where('id', $user)->where('role', 'admin')->firstOrFail();

    // Audit 2026-09-01: the signed link travels by e-mail. Only the admin it was
    // issued for, already signed in to the panel, may act on it — a leaked or
    // forwarded link must not be able to whitelist somebody else's IP.
    if (auth()->id() !== $admin->id) {
        abort(403, 'Влезте в администрацията с този акаунт и отворете линка отново.');
    }

    AdminTrustedIp::firstOrCreate(
        ['user_id' => $admin->id, 'ip_address' => $ip],
        ['label' => 'Marked from email alert', 'first_seen_at' => now(), 'last_seen_at' => now()],
    );

    return response()->view('emails.admin-trust-ip-confirmed', [
        'ip' => $ip,
        'admin' => $admin,
    ]);
})->middleware('signed')->name('admin.trust-ip');

// Serve private KYC documents — only accessible by admin
Route::get('/admin/kyc-document/{path}', function (string $path) {
    if (! auth()->user()?->isAdmin()) {
        abort(403);
    }

    // Block path traversal attempts
    if (str_contains($path, '..') || str_starts_with($path, '/')) {
        abort(403);
    }

    $fullPath = 'kyc-documents/'.$path;
    if (! Storage::disk('local')->exists($fullPath)) {
        abort(404);
    }

    // Double-check resolved path stays within KYC directory
    $basePath = Storage::disk('local')->path('kyc-documents');
    $resolvedPath = Storage::disk('local')->path($fullPath);
    if (! str_starts_with(realpath($resolvedPath) ?: $resolvedPath, realpath($basePath) ?: $basePath)) {
        abort(403);
    }

    // Audit 2026-09-01 (SEC-25): who opened whose identity document, and when.
    $owner = User::query()
        ->where('kyc_document_front_path', $fullPath)
        ->orWhere('kyc_document_back_path', $fullPath)
        ->orWhere('kyc_selfie_path', $fullPath)
        ->first(['id', 'kyc_document_front_path', 'kyc_document_back_path', 'kyc_selfie_path']);
    $kind = match (true) {
        $owner?->kyc_document_front_path === $fullPath => 'front',
        $owner?->kyc_document_back_path === $fullPath => 'back',
        $owner?->kyc_selfie_path === $fullPath => 'selfie',
        default => 'unknown',
    };
    AuditLog::recordAccess(User::class, $owner?->id ?? 0, ['document' => 'kyc_'.$kind]);

    // Identity documents must never land in a browser or proxy cache.
    return response()->file($resolvedPath, ['Cache-Control' => 'no-store, private']);
})->where('path', '.*')->middleware(['web', 'auth'])->name('admin.kyc-document');

// Serve a concluded investment contract PDF to the admin (Filament link).
// Rendered on demand from the frozen snapshot — nothing on disk, so no
// path handling at all (route-model binding by id only).
Route::get('/admin/investment-contract/{investment}', function (Investment $investment, InvestmentContractService $service) {
    if (! auth()->user()?->isAdmin()) {
        abort(403);
    }

    $contract = $investment->contract;
    abort_if($contract === null, 404);

    AuditLog::recordAccess(InvestmentContract::class, $contract->id, [
        'document' => 'investment_contract',
        'investment_id' => $investment->id,
    ]);

    return response($service->renderPdf($contract), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => "inline; filename=\"dogovor-zaem-inv-{$investment->id}.pdf\"",
        'Cache-Control' => 'no-store, private',
    ]);
})->middleware(['web', 'auth'])->name('admin.investment-contract');

// Vue SPA catch-all (excludes /admin which is handled by Filament)
// SEC-22 (owner 2026-09-03): the signed e-mail links of the account-deletion
// flow. No login on purpose — the mailbox is the second factor. The hash is an
// HMAC of (id|email|requested_at): a cancel or a re-request kills every earlier
// link. GET only renders a one-button page (link scanners prefetch mail URLs);
// the action runs on the POST from it — see AccountDeletionLinkController.
Route::match(['get', 'post'], '/account/deletion/confirm/{user}/{hash}', [AccountDeletionLinkController::class, 'confirm'])
    ->middleware(['signed', 'throttle:10,1,deletion-link'])
    ->name('account.deletion.confirm');

Route::match(['get', 'post'], '/account/deletion/cancel/{user}/{hash}', [AccountDeletionLinkController::class, 'cancel'])
    ->middleware(['signed', 'throttle:10,1,deletion-link'])
    ->name('account.deletion.cancel');

// SEC-16: an archived identity document of a closed account. The path comes
// from the row, never from the URL; admin only; every view audited; never cached.
Route::get('/admin/kyc-retained/{retention}/{kind}', function (KycRetention $retention, string $kind, KycRetentionService $service) {
    if (! auth()->user()?->isAdmin()) {
        abort(403);
    }
    abort_if($retention->isPurged(), 404);
    abort_unless(array_key_exists($kind, KycRetention::KIND_COLUMNS), 404);

    $absolute = $service->absoluteDocumentPath($retention, $kind);
    abort_if($absolute === null, 404);

    AuditLog::recordAccess(KycRetention::class, $retention->id, [
        'document' => 'kyc_'.$kind,
        'subject_user_id' => $retention->user_id,
    ]);

    return response()->file($absolute, ['Cache-Control' => 'no-store, private']);
})->whereNumber('retention')->middleware(['web', 'auth'])->name('admin.kyc-retained');

// SEC-01 (owner 2026-09-03): signed e-mail link that confirms a newly added
// payout IBAN. No login required — mailbox possession IS the factor (the
// attacker in this threat model already holds the password). GET renders the
// one-button page, POST confirms; every outcome ends on the SPA profile.
Route::match(['get', 'post'], '/ibans/confirm/{iban}/{token}', SavedIbanConfirmationController::class)
    ->middleware(['signed', 'throttle:10,1,iban-confirm'])
    ->name('ibans.confirm');

Route::get('/{any}', fn () => view('app'))->where('any', '^(?!admin).*$');
