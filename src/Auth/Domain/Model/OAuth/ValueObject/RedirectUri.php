<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use Uri\WhatWg\Url;

/**
 * A redirect URI read the way a browser reads it (the WHATWG URL standard).
 *
 * A browser ends the host at a backslash in an http URL, so a lenient parser can see a
 * different host than the one the browser then visits. A URI with a backslash anywhere,
 * userinfo or a fragment (RFC 6749 section 3.1.2) is therefore refused outright.
 */
final readonly class RedirectUri
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private function __construct(private Url $url)
    {
    }

    /** The parsed URI, or null when it does not parse or carries a backslash, userinfo or a fragment. */
    public static function parse(string $uri): ?self
    {
        if (str_contains($uri, '\\')) {
            return null;
        }

        $url = Url::parse($uri);
        if ($url === null || self::hasUserinfo($url) || $url->getFragment() !== null) {
            return null;
        }

        return new self($url);
    }

    public function scheme(): string
    {
        return $this->url->getScheme();
    }

    public function host(): string
    {
        return $this->url->getAsciiHost() ?? '';
    }

    /** Plain http on a loopback host (RFC 8252 section 7.3). */
    public function isLoopback(): bool
    {
        return $this->url->getScheme() === 'http' && in_array($this->host(), self::LOOPBACK_HOSTS, true);
    }

    /**
     * Whether a browser would send both URIs to the same loopback endpoint on any port:
     * scheme, host, path and query are equal, and only the port may differ.
     */
    public function isSameLoopbackEndpointAs(self $other): bool
    {
        return $this->isLoopback()
            && $other->isLoopback()
            && $this->host() === $other->host()
            && $this->url->getPath() === $other->url->getPath()
            && $this->url->getQuery() === $other->url->getQuery();
    }

    private static function hasUserinfo(Url $url): bool
    {
        return ($url->getUsername() ?? '') !== '' || ($url->getPassword() ?? '') !== '';
    }
}
