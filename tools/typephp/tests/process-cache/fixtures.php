<?php

declare(strict_types=1);

namespace TypephpProcessCacheFixture;

function label(string $value): string
{
    return 'global:'.$value;
}

interface Value
{
}

class ParentValue
{
}

final class ChildValue extends ParentValue implements Value
{
}

function identity(Value $value): Value
{
    return $value;
}

final class CallableConstants
{
    public const GLOBAL_CALL = label(...);
    public const STATIC_CALL = self::label(...);

    public static function label(string $value): string
    {
        return 'static:'.$value;
    }
}

function coldLabel(string $value): string
{
    return 'cold-global:'.$value;
}

final class ColdCallableConstants
{
    public const GLOBAL_CALL = coldLabel(...);
    public const STATIC_CALL = self::label(...);

    public static function label(string $value): string
    {
        return 'cold-static:'.$value;
    }
}
