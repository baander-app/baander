<?php

namespace App\Services\Widgets;

use App\Services\Widgets\Types\{MainNavBar, MainNavBarLink, MainNavBarSection, Widget};

class WidgetTypeProvider
{
    /**
     * @var Widget[]
     */
    protected array $types = [
        MainNavBar::class,
        MainNavBarLink::class,
        MainNavBarSection::class,
    ];

    public function getTypes()
    {
        return $this->types;
    }

    public function getSchemaFromWidgetName(string $widgetName)
    {
        foreach ($this->types as $type) {
            if ($type::getName() === $widgetName) {
                return $type::getSchema();
            }
        }

        throw new \RuntimeException('Widget type not defined: ' . $widgetName);
    }

    public function getWidgets()
    {
        return collect($this->types)->map(function ($type) {
            return [
                'id'   => $type::getId(),
                'name' => $type::getName(),
            ];
        });
    }

}