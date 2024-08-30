<?php

namespace App\Services\Widgets\Types;

use App\Services\Widgets\WidgetInterface;
use JsonSerializable;

abstract class Widget implements WidgetInterface
{
    public static function getId(): string
    {
        return route('api.schemas.widget', ['name' => static::getName()]);
    }

    public static function getType(): string
    {
        return 'object';
    }

    public static function getClassName(): string
    {
        return class_basename(static::class);
    }

    abstract public static function getName(): string;

    abstract public function toArray(): array;

    public static function getSchema(): array
    {
        return [
            'id'   => static::getId(),
            'type' => static::getType(),
        ];
    }
}