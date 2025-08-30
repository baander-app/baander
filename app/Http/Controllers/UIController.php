<?php

namespace App\Http\Controllers;

use App\Baander;
use App\Services\AppConfigService;

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
        ]);
    }
}
