<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\AssignmentChainPipeOperator;

use Closure;

final class ClosuresAndFirstClassCallables
{
    public function arrowFunctionFirstValue(): Closure
    {
        $f = static fn (string $v): string => $v;
        $f = self::wrap($f);
        $f = self::wrap($f);

        return $f;
    }

    public function closureFirstValue(): Closure
    {
        $f = function (string $v): string {
            return $v;
        };
        $f = self::wrap($f);
        $f = self::wrap($f);

        return $f;
    }

    /** @return array<string> */
    public function firstClassCallableInAContinuation(string $csv): array
    {
        $parts = explode(',', $csv);
        $parts = array_map(trim(...), $parts);
        $parts = array_filter($parts);

        return $parts;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function firstClassCallableInTheFirstValue(array $values): array
    {
        $parts = array_map(trim(...), $values);
        $parts = array_filter($parts);
        $parts = array_values($parts);

        return $parts;
    }

    private static function wrap(Closure $f): Closure
    {
        return $f;
    }
}
