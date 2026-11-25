<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

use Closure;

final class VariableCallables
{
    public function variableCallableOnTop(Closure $format, string $s): string
    {
        return $format(trim(strtolower($s)));
    }

    public function variableCallableInnermost(Closure $read, string $key): string
    {
        return strtoupper(trim($read($key)));
    }
}
