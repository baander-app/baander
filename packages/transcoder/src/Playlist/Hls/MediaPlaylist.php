<?php

namespace Baander\Transcoder\Playlist\Hls;

use Baander\Transcoder\Playlist\PlaylistInterface;

class MediaPlaylist implements PlaylistInterface
{
    private int $version = 12;
    private int $targetDuration = 10; // Required: Maximum segment duration.
    private int $mediaSequence = 0; // Required: Media sequence start index.
    private ?string $playlistType = null; // Optional: VOD or EVENT.
    private bool $independentSegments = false;
    private ?ServerControl $serverControl = null; // For low-latency HLS.
    private ?Start $start = null; // Optional playback start offset.
    /** @var Segment[] */
    private array $segments = []; // List of segments.
    private bool $hasEndList = false; // Whether the playlist ends.
    /** @var Part[] */
    private array $parts = []; // List of #EXT-X-PART.

    public function validate(): void
    {
        if ($this->targetDuration <= 0) {
            throw new \InvalidArgumentException('TARGETDURATION must be greater than 0.');
        }

        if (empty($this->segments) && empty($this->parts)) {
            throw new \InvalidArgumentException('Playlist must contain at least one segment or partial segment.');
        }
    }

    /**
     * Set the HLS version.
     */
    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Set the target duration (#EXT-X-TARGETDURATION).
     */
    public function setTargetDuration(int $duration): self
    {
        $this->targetDuration = $duration;
        return $this;
    }

    /**
     * Set the Media Sequence (#EXT-X-MEDIA-SEQUENCE).
     */
    public function setMediaSequence(int $sequence): self
    {
        $this->mediaSequence = $sequence;
        return $this;
    }

    /**
     * Set the Playlist Type (#EXT-X-PLAYLIST-TYPE).
     */
    public function setPlaylistType(string $type): self
    {
        if (!in_array($type, ['VOD', 'EVENT'])) {
            throw new \InvalidArgumentException('Invalid playlist type. Allowed types: VOD, EVENT.');
        }

        $this->playlistType = $type;
        return $this;
    }

    /**
     * Mark the playlist with independent segments (#EXT-X-INDEPENDENT-SEGMENTS).
     */
    public function setIndependentSegments(bool $flag = true): self
    {
        $this->independentSegments = $flag;
        return $this;
    }

    /**
     * Set Server Control for low-latency HLS (#EXT-X-SERVER-CONTROL).
     */
    public function setServerControl(ServerControl $serverControl): self
    {
        $this->serverControl = $serverControl;
        return $this;
    }

    /**
     * Set the Start offset (#EXT-X-START).
     */
    public function setStart(Start $start): self
    {
        $this->start = $start;
        return $this;
    }

    /**
     * Add a segment to the playlist.
     */
    public function addSegment(Segment $segment): self
    {
        $this->segments[] = $segment;
        return $this;
    }

    public function addPart(Part $part): self
    {
        $this->parts[] = $part;
        return $this;
    }

    /**
     * Mark the playlist with an ENDLIST tag (#EXT-X-ENDLIST).
     */
    public function setEndList(bool $flag = true): self
    {
        $this->hasEndList = $flag;
        return $this;
    }

    /**
     * Convert the Media Playlist to a string.
     */
    public function toString(): string
    {
        $lines = ['#EXTM3U', "#EXT-X-VERSION:{$this->version}"];
        $lines[] = "#EXT-X-TARGETDURATION:{$this->targetDuration}";
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:{$this->mediaSequence}";

        if ($this->playlistType) {
            $lines[] = "#EXT-X-PLAYLIST-TYPE:{$this->playlistType}";
        }

        if ($this->independentSegments) {
            $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        }

        if ($this->serverControl) {
            $lines[] = $this->serverControl->toString();
        }

        if ($this->start) {
            $lines[] = $this->start->toString();
        }

        foreach ($this->segments as $segment) {
            $lines[] = $segment->toString();
        }

        foreach ($this->parts as $part) {
            $lines[] = $part->toString();
        }

        if ($this->hasEndList) {
            $lines[] = '#EXT-X-ENDLIST';
        }

        return implode("\n", $lines);
    }
}
