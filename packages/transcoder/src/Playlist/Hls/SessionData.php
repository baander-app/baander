<?php

namespace Baander\Transcoder\Playlist\Hls;

use Baander\Common\Contracts\StringableInterface;

/**
 * Represents a session metadata (#EXT-X-SESSION-DATA).
 */
class SessionData implements StringableInterface
{
    private string $dataId;
    private string $value;
    private ?string $language;

    public function __construct(string $dataId, string $value, ?string $language = null)
    {
        $this->dataId = $dataId;
        $this->value = $value;
        $this->language = $language;
    }

    public function toString(): string
    {
        $attributes = [
            "DATA-ID=\"{$this->dataId}\"",
            "VALUE=\"{$this->value}\"",
        ];

        if ($this->language) {
            $attributes[] = "LANGUAGE=\"{$this->language}\"";
        }

        return '#EXT-X-SESSION-DATA:' . implode(',', $attributes);
    }
}