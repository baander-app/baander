<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/** Reads PHP's memory_limit ini value. */
final class MemoryLimit
{
    /**
     * The limit in bytes, parsed the way PHP parses ini quantities (a byte count, or a
     * number with a K, M or G suffix). Null for -1, which means no limit.
     */
    public static function parse(string $value): ?int
    {
        $bytes = ini_parse_quantity($value);

        return $bytes < 0 ? null : $bytes;
    }
}
