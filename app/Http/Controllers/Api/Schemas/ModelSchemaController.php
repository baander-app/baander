<?php

namespace App\Http\Controllers\Api\Schemas;

use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\TokenAbility;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('schemas/models')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class ModelSchemaController extends Controller
{
    #[Get('', 'api.schemas.model')]
    public function getModelSchema(Request $request)
    {
        $model = new Library;

        return response()->json($model->getJsonSchema());
    }
}
