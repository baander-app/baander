<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NativeExecutionProtocol
{
    public const int REQUEST_BYTES = 65_536;
    public const int OUTPUT_BYTES = 1_048_576;
    public const int RESPONSE_BYTES = 3_000_000;
    public const int MAX_COMMANDS = 8;
    public const int MAX_RESULTS = 32;
    public const int MAX_CLIENTS = 64;

    /** @param array<string, mixed> $value */
    public static function encode(array $value, int $limit): string
    {
        $frame = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (strlen($frame) > $limit) {
            throw new NativeExecutionException('Native execution frame exceeds its byte limit.', false);
        }

        return $frame;
    }

    /** @return array<string, mixed> */
    public static function decode(string $frame, int $limit): array
    {
        if (strlen($frame) > $limit || !str_ends_with($frame, "\n") || substr_count($frame, "\n") !== 1) {
            throw new NativeExecutionException('Malformed native execution frame.');
        }
        try {
            $value = json_decode($frame, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NativeExecutionException('Native execution frame is not valid JSON.');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new NativeExecutionException('Native execution frame must be an object.');
        }

        return $value;
    }
}
