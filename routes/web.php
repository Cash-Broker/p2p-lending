<?php

use Illuminate\Support\Facades\Route;

// Vue SPA catch-all (excludes /admin which is handled by Filament)
Route::get('/{any}', fn () => view('app'))->where('any', '^(?!admin).*$');
