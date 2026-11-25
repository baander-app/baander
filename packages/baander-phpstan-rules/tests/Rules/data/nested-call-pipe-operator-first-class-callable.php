<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

final class FirstClassCallableArguments
{
    /** @param list<string> $aliases */
    public function besideTheInnermostValue(array $aliases): array
    {
        return array_values(array_unique(array_map(trim(...), $aliases)));
    }

    /** @param list<string> $command */
    public function twoLevelsAboveIt(array $command): int
    {
        return array_sum(array_map(strlen(...), $command));
    }

    public function besideACallArgument(string $csv): string
    {
        return implode(',', array_map(trim(...), explode(',', $csv)));
    }

    public function staticMethodBesideACallArgument(string $csv): string
    {
        return implode(',', array_map(self::clean(...), explode(',', $csv)));
    }

    public function asTheOnlyArgument(): string|false
    {
        return json_encode(is_callable(trim(...)));
    }

    private static function clean(string $part): string
    {
        return trim($part);
    }
}
