<?php

namespace App\Packages\PhpInfoParser\Concerns;

use App\Packages\PhpInfoParser\Result;

/**
 * @mixin Result
 */
trait ConfigAliases
{
    protected array $aliases = [
        'os',
        'hostname',
    ];

    protected function getOs(): string
    {
        return explode(' ', $this->config('System'))[0];
    }

    protected function getHostname(): string
    {
        return explode(' ', $this->config('System'))[1];
    }
}