<?php

use Illuminate\Support\Facades\Route;

Route::get('/dbg', [\App\Http\Controllers\UIController::class, 'dbg'])->name('dbg');

Route::get('/{any}', [\App\Http\Controllers\UIController::class, 'getUI'])
    ->name('webui')
    ->where('any', '^(?!api|docs|storage|public-api|clockwork|system|2fa|login|dbg|jobs).*$');

