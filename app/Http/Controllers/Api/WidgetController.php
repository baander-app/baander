<?php

namespace App\Http\Controllers\Api;

use App\Models\TokenAbility;
use App\Services\Widgets\Types\MainNavBar;
use App\Services\Widgets\WidgetService;
use BeyondCode\ServerTiming\Facades\ServerTiming;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('widgets')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class WidgetController
{
    public function __construct(
        private readonly WidgetService $widgetService
    )
    {
    }

    /**
     * Get a widget for the user
     *
     * @param Request $request
     * @param string $name
     * @return \Illuminate\Http\JsonResponse
     */
    #[Get('{widget}', 'api.widgets.getWidget')]
    public function getWidget(Request $request, string $name)
    {
        $navBar = null;

        ServerTiming::addMetric('Widget:' . $name);
        ServerTiming::setDuration('Building widget', function () use (&$navBar, $name, $request) {
            $navBar = $this->widgetService->getWidget($name, $request->user());
        });

        return response()->json($navBar);
    }
}