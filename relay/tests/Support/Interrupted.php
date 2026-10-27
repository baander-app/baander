<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Interrupted extends \RuntimeException
{
    public function __construct(public readonly int $signal)
    {
        parent::__construct('Registry contract interrupted by signal ' . $signal . '.');
    }
}
