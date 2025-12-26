<?php

use App\Http\Controllers\UIController;
use Illuminate\Support\Facades\Route;

Route::get('/help', [UIController::class, 'getDocs'])
    ->name('ui.docs-get')
    ->middleware('cors.policy');

Route::get('/{any}', [UIController::class, 'getUI'])
    ->name('webui')
    ->middleware('cors.policy')
    ->where('any', '^(?!api|help|docs|storage|public-api|clockwork|system|2fa|login|dbg|jobs).*$');

