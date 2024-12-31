<?php

namespace App\Http\Controllers\Api;

use App\Models\TokenAbility;
use App\Packages\PhpInfoParser\Info;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/system-info')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class SystemInfoController
{
    /**
     * @response array{
     *   section: string,
     *   values: array{
     *     name: string,
     *     value: string|int|bool|float|null,
     *   }[]
     * }[]
     */
    #[Get('/', 'api.system-info.show')]
    public function php()
    {
        return response()->json(Info::getModules());
    }


}