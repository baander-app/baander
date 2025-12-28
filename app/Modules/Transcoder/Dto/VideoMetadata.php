<?php

namespace App\Modules\Transcoder\Dto;

use DateTimeImmutable;

readonly class VideoMetadata
{
    public function __construct(
        public string             $id,
        public string             $title,
        public int                $duration,
        public int                $width,
        public int                $height,
        public float              $fps,
        public string             $codec,
        public int                $bitrate,
        public string             $sourcePath,
        public array              $availableQualities = [],
        public array              $availableFormats = [],
        public ?string            $thumbnail = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    )
    {
    }

    /**
     * Create from array (API response)
     * @throws \DateMalformedStringException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? 'Unknown',
            duration: (int)($data['duration'] ?? 0),
            width: (int)($data['width'] ?? 1920),
            height: (int)($data['height'] ?? 1080),
            fps: (float)($data['fps'] ?? 30.0),
            codec: $data['codec'] ?? 'h264',
            bitrate: (int)($data['bitrate'] ?? 0),
            sourcePath: $data['sourcePath'] ?? '',
            availableQualities: $data['availableQualities'] ?? [],
            availableFormats: $data['availableFormats'] ?? [],
            thumbnail: $data['thumbnail'] ?? null,
            createdAt: isset($data['createdAt']) ? new DateTimeImmutable($data['createdAt']) : null,
            updatedAt: isset($data['updatedAt']) ? new DateTimeImmutable($data['updatedAt']) : null,
        );
    }

    /**
     * Get duration in human-readable format
     */
    public function getDurationFormatted(): string
    {
        $hours = floor($this->duration / 3600);
        $minutes = floor(($this->duration % 3600) / 60);
        $seconds = $this->duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get resolution string
     */
    public function getResolution(): string
    {
        return "{$this->width}x{$this->height}";
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'title'              => $this->title,
            'duration'           => $this->duration,
            'width'              => $this->width,
            'height'             => $this->height,
            'fps'                => $this->fps,
            'codec'              => $this->codec,
            'bitrate'            => $this->bitrate,
            'sourcePath'         => $this->sourcePath,
            'availableQualities' => $this->availableQualities,
            'availableFormats'   => $this->availableFormats,
            'thumbnail'          => $this->thumbnail,
            'createdAt'          => $this->createdAt?->format('c'),
            'updatedAt'          => $this->updatedAt?->format('c'),
        ];
    }
}
