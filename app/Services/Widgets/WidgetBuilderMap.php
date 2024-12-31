<?php

namespace App\Services\Widgets;

use App\Services\Widgets\Builders\BuilderInterface;

class WidgetBuilderMap
{
    private array $map;

    public function __construct(array $map)
    {
        $this->map = $map;
    }

    public function getBuilder(string $widgetName)
    {
        if (!isset($this->map[$widgetName])) {
            throw new \RuntimeException("Cannot find builder for " . $widgetName);
        }

        return $this->map[$widgetName];
    }
}