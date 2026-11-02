<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\TwoCommand;

use RuntimeException;

/** Keeps signed playback probes inside the disposable web container. */
final class LocalOrigin
{
    public const string BASE = 'http://127.0.0.1:9501';

    public static function resolve(string $url): string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 0 || str_starts_with($url, '//')) {
            throw new RuntimeException('Playback URL must target the disposable web service.');
        }
        $resolved = str_starts_with($url, '/') ? self::BASE . $url : $url;
        $parts = parse_url($resolved);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'http' || ($parts['host'] ?? null) !== '127.0.0.1'
            || ($parts['port'] ?? null) !== 9501 || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Playback URL must target the disposable web service.');
        }
        return $resolved;
    }

    /** @return list<string> */
    public static function ffmpegInput(string $url): array
    {
        return ['-max_redirects', '0', '-i', self::resolve($url)];
    }
}
