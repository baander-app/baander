<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('2fa')]
class TwoFactorController
{
    #[Get('/confirm')]
    public function confirmTwoFactorCode(Request $request)
    {
        return view('two_factor.confirm_code');
    }
}