<?php

use App\Http\Controllers\UIController;
use Illuminate\Support\Facades\Route;

Route::get('/dbg', [UIController::class, 'dbg'])->name('dbg');

Route::get('/{any}', [UIController::class, 'getUI'])
    ->name('webui')
    ->where('any', '^(?!api|docs|storage|public-api|clockwork|system|2fa|login|dbg|jobs).*$');

