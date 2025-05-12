<?php

namespace Baander\Transcoder\Playlist\Hls;

class AudioStream
{
    private string $groupId;
    private string $name;
    private string $language;
    private string $uri;
    private bool $autoSelect;
    private bool $default;

    public function __construct(
        string $groupId,
        string $name,
        string $language,
        string $uri,
        bool $autoSelect = false,
        bool $default = false
    ) {
        $this->groupId = $groupId;
        $this->name = $name;
        $this->language = $language;
        $this->uri = $uri;
        $this->autoSelect = $autoSelect;
        $this->default = $default;
    }

    public function toString(): string
    {
        return sprintf(
            '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="%s",NAME="%s",LANGUAGE="%s",AUTOSELECT=%s,DEFAULT=%s,URI="%s"',
            $this->groupId,
            $this->name,
            $this->language,
            $this->autoSelect ? 'YES' : 'NO',
            $this->default ? 'YES' : 'NO',
            $this->uri
        );
    }
}