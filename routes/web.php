<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

// Vue SPA catch-all (excludes /admin which is handled by Filament)
Route::get('/{any}', fn () => view('app'))->where('any', '^(?!admin).*$');
