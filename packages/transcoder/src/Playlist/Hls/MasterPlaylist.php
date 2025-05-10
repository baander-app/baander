<?php

namespace Baander\Transcoder\Playlist\Hls;

use Baander\Transcoder\Playlist\PlaylistInterface;

class MasterPlaylist implements PlaylistInterface
{
    private int $version = 12;
    private ?string $title = null;
    private ?string $source = null;
    private ?string $serverVersion = null;
    private array $streams = [];
    /** @var Media[] */
    private array $medias = [];
    /** @var SessionData[] */
    private array $sessionData = [];
    /** @var Key[] */
    private array $sessionKeys = [];
    private ?Start $start = null;
    private bool $independentSegments = false;
    private string $baseUri = ''; // Base URI for media playlist references.
    /** @var VideoStream[] */
    private array $videoStreams = [];
    /** @var AudioStream[] */
    private array $audioStreams = [];

    public function validate(): void
    {
        if (empty($this->streams) && empty($this->medias)) {
            throw new \InvalidArgumentException('Master Playlist must contain at least one `#EXT-X-STREAM-INF` or `#EXT-X-MEDIA` tag.');
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

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function setServerVersion(string $serverVersion): void
    {
        $this->serverVersion = $serverVersion;
    }

    /**
     * Set the base URI for media playlist references.
     */
    public function setBaseUri(string $baseUri): self
    {
        // Ensure the base URI always ends with a slash.
        $this->baseUri = rtrim($baseUri, '/') . '/';
        return $this;
    }

    public function addVideoStream(VideoStream $videoStream): self
    {
        $this->videoStreams[] = $videoStream;
        return $this;
    }

    public function addAudioStream(AudioStream $audioStream): self
    {
        $this->audioStreams[] = $audioStream;
        return $this;
    }

    /**
     * Add a variant stream with a MediaPlaylist.
     */
    public function addStreamWithMedia(MediaPlaylist $mediaPlaylist, int $bandwidth, ?string $resolution = null, ?string $codecs = null): self
    {
        $uri = $this->generateMediaPlaylistUri($mediaPlaylist);
        $stream = new Stream($bandwidth, $uri)
            ->setResolution($resolution)
            ->setCodecs($codecs);

        $this->streams[] = $stream;
        return $this;
    }

    /**
     * Generate the URI for a MediaPlaylist.
     */
    protected function generateMediaPlaylistUri(MediaPlaylist $mediaPlaylist): string
    {
        $filename = 'playlist-' . hash('sha256', $mediaPlaylist->toString()) . '.m3u8';

        return $this->baseUri . $filename;
    }

    /**
     * Add alternate media renditions.
     */
    public function addMedia(Media $media): self
    {
        $this->medias[] = $media;
        return $this;
    }

    /**
     * Set independent segments.
     */
    public function setIndependentSegments(bool $flag = true): self
    {
        $this->independentSegments = $flag;
        return $this;
    }

    /**
     * Set a playback start.
     */
    public function setStart(Start $start): self
    {
        $this->start = $start;
        return $this;
    }

    /**
     * Add session-level metadata.
     */
    public function addSessionData(SessionData $sessionData): self
    {
        $this->sessionData[] = $sessionData;
        return $this;
    }

    /**
     * Add session encryption key.
     */
    public function addSessionKey(Key $key): self
    {
        $this->sessionKeys[] = $key;
        return $this;
    }

    /**
     * Convert the master playlist to string.
     * This is what should be hosted as the master playlist.
     */
    public function toString(): string
    {
        $lines = ['#EXTM3U', "#EXT-X-VERSION:{$this->version}", "EXT-X-SERVER:Baander"];

        if ($this->title) {
            $lines[] = "#EXT-X-BAANDER-TITLE:{$this->title}";
        }

        if ($this->source) {
            $lines[] = "#EXT-X-BAANDER-SOURCE:{$this->source}";
        }

        if ($this->serverVersion) {
            $lines[] = "#EXT-X-BAANDER-SERVER-VERSION:{$this->serverVersion}";
        }

        if ($this->independentSegments) {
            $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        }

        if ($this->start) {
            $lines[] = $this->start->toString();
        }

        foreach ($this->sessionData as $data) {
            $lines[] = $data->toString();
        }

        foreach ($this->sessionKeys as $key) {
            $lines[] = $key->toString();
        }

        foreach ($this->medias as $media) {
            $lines[] = $media->toString();
        }

        foreach ($this->streams as $stream) {
            $lines[] = $stream->toString();
        }

        foreach ($this->videoStreams as $videoStream) {
            $lines[] = $videoStream->toString();
        }

        foreach ($this->audioStreams as $audioStream) {
            $lines[] = $audioStream->toString();
        }

        return implode("\n", $lines);
    }
}