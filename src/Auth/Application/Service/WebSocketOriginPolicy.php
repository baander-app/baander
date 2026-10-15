<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

final readonly class WebSocketOriginPolicy
{
    /** @var list<string> */
    private array $allowedOrigins;

    /** @param list<string>|null $allowedOrigins Null derives the application origin; empty denies all. */
    public function __construct(string $applicationUrl, ?array $allowedOrigins = null)
    {
        $this->allowedOrigins = $allowedOrigins ?? [self::applicationOrigin($applicationUrl)];
        foreach ($this->allowedOrigins as $origin) {
            if (!self::isSerializedOrigin($origin)) {
                throw new \InvalidArgumentException('WebSocket allowed origins must be serialized HTTP or HTTPS origins.');
            }
        }
    }

    public function allows(string $origin): bool
    {
        return self::isSerializedOrigin($origin) && in_array($origin, $this->allowedOrigins, true);
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    private static function applicationOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('Application URL must have an HTTP or HTTPS origin.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $suffix = $port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)
            ? '' : ':' . $port;

        return $scheme . '://' . strtolower($parts['host']) . $suffix;
    }

    private static function isSerializedOrigin(string $origin): bool
    {
        if ($origin === '' || preg_match('/[\x00-\x20\x7f]/', $origin) === 1) {
            return false;
        }
        $parts = parse_url($origin);

        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && in_array($parts['scheme'], ['https', 'http'], true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment'])
            && self::applicationOrigin($origin) === $origin;
    }
}
