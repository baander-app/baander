<?php

namespace App;

class Baander
{
    public const string VERSION = '0.0.0-alpha';

    public static function getAppInfo(): array
    {
        return [
            'name'        => config('app.name'),
            'url'         => config('app.url'),
            'environment' => config('app.env'),
            'debug'       => config('app.debug'),
            'locale'      => config('app.locale'),
            'version'     => Baander::VERSION,
        ];
    }
}