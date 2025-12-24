<?php

namespace App\Http\Controllers;

use App\Baander;
use App\Services\AppConfigService;
use Illuminate\Support\Facades\Auth;

class UIController
{
    public function __construct(
        private readonly AppConfigService $appConfigService,
    )
    {}

    public function getUI()
    {
        return view('app', [
            'appConfigData' => $this->appConfigService->getAppConfig()->toArray(),
            'oAuthConfig' => Auth::check() ? $this->appConfigService->getOAuthConfig() : [],
        ]);
    }

    public function getDocs()
    {
        return view('docs', [
            'appConfigData' => $this->appConfigService->getAppConfig()->toArray(),
            'oAuthConfig' => Auth::check() ? $this->appConfigService->getOAuthConfig() : [],
        ]);
    }
}
