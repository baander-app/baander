<?php

namespace App\Services\Widgets;

interface WidgetInterface
{
    public static function getId(): string;
    public static function getClassName(): string;
    public static function getSchema(): array;
    public static function getName(): string;

    public function toArray();

}