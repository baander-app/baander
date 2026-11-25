<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

final class SpreadArguments
{
    /**
     * @param list<string> $diagnostics
     * @param array<string, string> $sources
     */
    public function spreadArgumentInTheChain(array $diagnostics, array $sources): array
    {
        array_push($diagnostics, ...self::missing(array_keys($sources)));

        return $diagnostics;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function missing(array $names): array
    {
        return $names;
    }
}
