<?php

namespace Baander\Transcoder\Playlist\Hls;

use Baander\Common\Contracts\StringableInterface;

/**
 * Represents a session encryption key (#EXT-X-SESSION-KEY).
 */
class Key implements StringableInterface
{
    private string $method;
    private ?string $uri;
    private ?string $iv;

    public function __construct(string $method, ?string $uri, ?string $iv = null)
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->iv = $iv;
    }

    public function toString(): string
    {
        $attributes = [
            "METHOD={$this->method}",
            $this->uri ? "URI=\"{$this->uri}\"" : null,
            $this->iv ? "IV={$this->iv}" : null,
        ];

        return '#EXT-X-SESSION-KEY:' . implode(',', array_filter($attributes));
    }
}
