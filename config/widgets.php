<?php

use App\Services\Widgets as Widgets;

return [
    'widget_cache_time' => 60 * 5, // 5 minutes
    'builders' => [
        // mapping widget types to builders
        Widgets\Types\MainNavBar::getName() => Widgets\Builders\MainNavBarBuilder::class,
    ],
];