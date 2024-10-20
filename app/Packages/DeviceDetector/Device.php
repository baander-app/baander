<?php

namespace App\Packages\DeviceDetector;

use Illuminate\Support\Facades\Facade;

class Device extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DeviceDetector::class;
    }
}