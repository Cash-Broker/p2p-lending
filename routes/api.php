<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public auth routes — rate limited to prevent abuse
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected routes — authenticated users
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/email/verification-notification', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:6,1');

    // Investor-only routes (verified email required)
    Route::middleware('investor')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Browse loans (no KYC required — investors can browse before verification)
        Route::get('/loans', [LoanController::class, 'index']);
        Route::get('/loans/favorites', [LoanController::class, 'favorites']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
        Route::post('/loans/{loan}/favorite', [LoanController::class, 'toggleFavorite']);

        // Browse deposit info (no KYC — user needs to see bank details)
        Route::get('/deposit', [DepositController::class, 'index']);
        Route::get('/deposit/history', [DepositController::class, 'history']);

        // Wallet balance
        Route::get('/wallet', [WithdrawalController::class, 'wallet']);

        // Portfolio & Transactions
        Route::get('/portfolio', [PortfolioController::class, 'index']);
        Route::get('/portfolio/summary', [PortfolioController::class, 'summary']);
        Route::get('/transactions', [TransactionController::class, 'index']);

        // Profile
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'changePassword']);
        Route::post('/profile/kyc', [ProfileController::class, 'submitKyc']);
        Route::get('/profile/ibans', [ProfileController::class, 'ibans']);
        Route::post('/profile/ibans', [ProfileController::class, 'storeIban']);
        Route::delete('/profile/ibans/{iban}', [ProfileController::class, 'destroyIban']);
        Route::post('/profile/delete', [ProfileController::class, 'deleteAccount']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/notifications', [NotificationController::class, 'destroyAll']);

        // Financial operations — KYC approval required
        Route::middleware('kyc')->group(function () {
            Route::post('/loans/{loan}/invest', [LoanController::class, 'invest']);
            Route::post('/withdrawal', [WithdrawalController::class, 'store']);
            Route::get('/withdrawal/history', [WithdrawalController::class, 'history']);
        });
    });
});

// Email verification — stateless via signed URL.
// User clicks from email without session, so we verify via hash, not auth.
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $user = User::findOrFail($id);

    if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
        abort(403, 'Invalid verification link.');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect(config('app.url') . '/login?verified=1');
})->middleware('signed')->name('verification.verify');
