<?php

use App\Models\AdminTrustedIp;
use App\Models\User;
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

    $fullPath = 'kyc-documents/' . $path;
    if (! Storage::disk('local')->exists($fullPath)) {
        abort(404);
    }

    // Double-check resolved path stays within KYC directory
    $basePath = Storage::disk('local')->path('kyc-documents');
    $resolvedPath = Storage::disk('local')->path($fullPath);
    if (! str_starts_with(realpath($resolvedPath) ?: $resolvedPath, realpath($basePath) ?: $basePath)) {
        abort(403);
    }

    return response()->file($resolvedPath);
})->where('path', '.*')->middleware(['web', 'auth'])->name('admin.kyc-document');

// Serve a concluded investment contract PDF to the admin (Filament link).
// Rendered on demand from the frozen snapshot — nothing on disk, so no
// path handling at all (route-model binding by id only).
Route::get('/admin/investment-contract/{investment}', function (App\Models\Investment $investment, App\Services\InvestmentContractService $service) {
    if (! auth()->user()?->isAdmin()) {
        abort(403);
    }

    $contract = $investment->contract;
    abort_if($contract === null, 404);

    return response($service->renderPdf($contract), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => "inline; filename=\"dogovor-zaem-inv-{$investment->id}.pdf\"",
        'Cache-Control' => 'no-store, private',
    ]);
})->middleware(['web', 'auth'])->name('admin.investment-contract');

// Vue SPA catch-all (excludes /admin which is handled by Filament)
Route::get('/{any}', fn () => view('app'))->where('any', '^(?!admin).*$');
