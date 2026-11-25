<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

final class NamedArguments
{
    public function namedArgumentInTheChain(array $values): array
    {
        return array_values(array_unique(array: array_keys($values)));
    }
}
