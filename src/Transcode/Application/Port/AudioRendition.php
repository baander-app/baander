<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

/**
 * A track's audio in one format and bitrate, ready to send.
 *
 * A complete rendition is a cached file that supports byte ranges. A
 * progressive rendition is an encode still being written: its chunks arrive as
 * the encoder produces them, so it cannot serve byte ranges.
 */
final readonly class AudioRendition
{
    /** @param iterable<string>|null $chunks */
    private function __construct(
        public AudioRenditionFormat $format,
        public ?string $path,
        private ?iterable $chunks,
    ) {
    }

    public static function complete(AudioRenditionFormat $format, string $path): self
    {
        return new self($format, $path, null);
    }

    /** @param iterable<string> $chunks */
    public static function progressive(AudioRenditionFormat $format, iterable $chunks): self
    {
        return new self($format, null, $chunks);
    }

    public function isComplete(): bool
    {
        return $this->path !== null;
    }

    /**
     * The bytes of a progressive rendition, in order. Iteration waits for the
     * encoder and ends when the rendition is complete or the encode has failed.
     *
     * @return iterable<string>
     */
    public function chunks(): iterable
    {
        return $this->chunks ?? [];
    }
}
