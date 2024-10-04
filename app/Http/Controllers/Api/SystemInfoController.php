<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Packages\PhpInfoParser\Info;
use App\Services\SystemMetricsCollectorService;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/system-info')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class SystemInfoController extends Controller
{
    /**
     * Get php info
     *
     * @response array{
     *   section: string,
     *   values: array{
     *     key: string,
     *     value: string|int|bool|float|null,
     *   }[]
     * }[]
     */
    #[Get('/', 'api.system-info.php')]
    public function php()
    {
        $this->gateCheckViewDashboard();

        return response()->json(Info::getModules());
    }

    /**
     * @response array{
     *   memoryUsage: int,
     *   systemLoadAverage: int[],
     *   swooleVm: array{
     *     object_num: int,
     *     resource_num: int
     *   }
     * }
     */
    #[Get('/sys', 'api.system-info.sys')]
    public function system()
    {
        $this->gateCheckViewDashboard();

        $service = app(SystemMetricsCollectorService::class);

        return response()->json([
            'memoryUsage'       => $service->memoryUsage(),
            'systemLoadAverage' => $service->systemLoadAverage(),
            'swooleVm'          => $service->swooleVm(),
        ]);
    }
}