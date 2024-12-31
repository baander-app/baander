<?php

namespace App\Modules\DeviceDetector;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin DeviceDetector
 */
class Device extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DeviceDetector::class;
    }
}