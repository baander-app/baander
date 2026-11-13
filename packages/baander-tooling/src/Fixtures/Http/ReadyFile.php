<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

/**
 * The file through which a fixture server tells its test where it listens.
 * The test polls for the file and reads it as soon as it exists, so the file
 * appears with its whole content or not at all.
 */
final class ReadyFile
{
    /** @param array<string, int|string> $payload */
    public static function publish(string $path, array $payload): void
    {
        $partial = $path . '.partial';
        file_put_contents($partial, json_encode($payload, JSON_THROW_ON_ERROR) . "\n");
        if (!rename($partial, $path)) {
            throw new \RuntimeException('Fixture ready file could not be published: ' . $path);
        }
    }
}
