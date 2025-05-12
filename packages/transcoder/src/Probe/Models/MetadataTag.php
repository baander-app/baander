<?php

namespace Baander\Transcoder\Probe\Models;

/**
 * Represents a metadata tag from FFprobe.
 */
final class MetadataTag
{
    public readonly string $key;
    public readonly string $value;

    public function __construct(string $key, string $value)
    {
        $this->key = $key;
        $this->value = $value;
    }
}