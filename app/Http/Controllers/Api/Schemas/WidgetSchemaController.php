<?php

namespace App\Http\Controllers\Api\Schemas;

use App\Http\Requests\Schemas\WidgetSchemaRequest;
use App\Http\Resources\Schemas\WidgetListItemResource;
use App\Models\TokenAbility;
use App\Services\Widgets\WidgetTypeProvider;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('schemas/widgets')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class WidgetSchemaController
{
    public function __construct(
        private readonly WidgetTypeProvider $widgetTypeProvider,
    )
    {
    }


    /**
     * Get a list of widgets
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<WidgetListItemResource>
     */
    #[Get('', 'api.schemas.widget')]
    public function getWidgets()
    {
        $schemas = $this->widgetTypeProvider->getWidgets();

        return WidgetListItemResource::collection($schemas);
    }

    /**
     * Get widget schema
     *
     * @param WidgetSchemaRequest $request
     * @param string $name Name of the schema
     * @return JsonResponse
     */
    #[Get('{name}', 'api.schemas.widget')]
    public function getWidget(WidgetSchemaRequest $request, string $name)
    {
        $schema = $this->widgetTypeProvider->getSchemaFromWidgetName($name);

        return response()->json($schema);
    }
}