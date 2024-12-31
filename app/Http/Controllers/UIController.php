<?php

namespace App\Http\Controllers;

use App\Extensions\BaseBuilder;
use App\Http\Integrations\Github\BaanderGhApi;
use App\Http\Resources\Album\AlbumResource;
use App\Models\Album;
use App\Packages\PhpInfoParser\Info;
use App\Services\SystemMetricsCollectorService;
use Illuminate\Http\Request;

class UIController
{
    public function getUI()
    {
        return view('app', [
            'appInfo' => [
                'name'        => config('app.name'),
                'environment' => config('app.env'),
                'debug'       => config('app.debug'),
                'locale'      => config('app.locale'),
            ],
        ]);
    }

    public function dbg()
    {
        $service = app(SystemMetricsCollectorService::class);

        $metrics = [
          'memoryUsage' => $service->memoryUsage(),
          'systemLoad' => $service->systemLoad(),
          'swooleVm' => $service->swooleVm(),
        ];

        dd($metrics);

        return view('dbg', [
        ]);
    }
}