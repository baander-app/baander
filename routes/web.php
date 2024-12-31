<?php

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;


\Laragear\WebAuthn\Http\Routes::register()->withoutMiddleware(VerifyCsrfToken::class);

Route::get('/{any}', [\App\Http\Controllers\UIController::class, 'getUI'])
    ->name('webui')
    ->where('any', '^(?!api|docs|storage|public-api|clockwork|system|2fa|login).*$');

