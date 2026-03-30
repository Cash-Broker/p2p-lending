<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Serve private KYC documents — only accessible by admin
Route::get('/admin/kyc-document/{path}', function (string $path) {
    if (! auth()->user()?->isAdmin()) {
        abort(403);
    }

    $fullPath = 'kyc-documents/' . $path;
    if (! Storage::disk('local')->exists($fullPath)) {
        abort(404);
    }

    return response()->file(Storage::disk('local')->path($fullPath));
})->where('path', '.*')->middleware(['web', 'auth'])->name('admin.kyc-document');

// Vue SPA catch-all (excludes /admin which is handled by Filament)
Route::get('/{any}', fn () => view('app'))->where('any', '^(?!admin).*$');
