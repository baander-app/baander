<?php

namespace App\Services\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use JetBrains\PhpStorm\ArrayShape;

class WidgetService
{
    public function __construct(
        private readonly WidgetBuilderMap $map
    )
    {
    }

    public function getWidget(string $name, User $user)
    {
        $cacheKey = $this->makeCacheKey([$name, $user->id]);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $class = $this->map->getBuilder($name);

        $data = (new $class([
            'user' => $user,
        ]))->build($name);

        Cache::put($cacheKey, $data, config('widgets.widget_cache_time'));

        return $data;
    }

    #[ArrayShape([
        'name'   => 'string',
        'userId' => 'string|int',
    ])]
    private function makeCacheKey(array $data)
    {
        [$name, $userId] = $data;

        return "{$name}_{$userId}";
    }
}