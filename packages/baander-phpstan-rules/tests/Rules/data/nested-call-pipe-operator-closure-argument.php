<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

final class ClosureArguments
{
    /** @param list<int> $values */
    public function arrowFunction(array $values): int
    {
        return array_sum(array_values(array_filter($values, fn (int $v): bool => $v > 0)));
    }

    /** @param list<int> $values */
    public function staticArrowFunction(array $values): int
    {
        return array_sum(array_values(array_filter($values, static fn (int $v): bool => $v > 0)));
    }

    /** @param list<int> $values */
    public function closure(array $values): int
    {
        return array_sum(array_values(array_filter($values, function (int $v): bool {
            return $v > 0;
        })));
    }

    /** @param list<int> $values */
    public function staticClosure(array $values): int
    {
        return array_sum(array_values(array_filter($values, static function (int $v): bool {
            return $v > 0;
        })));
    }
}
