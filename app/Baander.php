<?php

namespace App;

class Baander
{
    public const string VERSION = '0.0.0-alpha';

    public static function getPeerName(): string
    {
        return config('app.name') . '/v' . Baander::VERSION;
    }
}
