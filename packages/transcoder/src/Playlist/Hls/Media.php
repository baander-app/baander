<?php

namespace Baander\Transcoder\Playlist\Hls;

use Baander\Common\Contracts\StringableInterface;

/**
 * Represents alternate media (#EXT-X-MEDIA).
 */
class Media implements StringableInterface
{
    private string $type;
    private string $groupId;
    private string $name;
    private ?string $uri;

    public function __construct(string $type, string $groupId, string $name, ?string $uri = null)
    {
        $this->type = $type;
        $this->groupId = $groupId;
        $this->name = $name;
        $this->uri = $uri;
    }

    public function toString(): string
    {
        $attributes = [
            'TYPE=' . strtoupper($this->type),
            "GROUP-ID=\"{$this->groupId}\"",
            "NAME=\"{$this->name}\"",
        ];

        if ($this->uri) {
            $attributes[] = "URI=\"{$this->uri}\"";
        }

        return '#EXT-X-MEDIA:' . implode(',', $attributes);
    }
}
