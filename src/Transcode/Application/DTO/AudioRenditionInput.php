<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Application\Port\AudioRenditionFormat;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioRenditionInput
{
    public function __construct(
        public string $trackKey,
        public string $sourceSignature,
        public string $sourcePath,
        public AudioRenditionFormat $format,
        public ?int $bitrate,
        public string $outputDirectory,
        public string $mode = 'encode',
        public ?int $streamIndex = null,
        public ?string $outputContainer = null,
        public ?string $sourceHash = null,
    ) {
        if ($trackKey === '' || $sourceSignature === '' || basename($sourceSignature) !== $sourceSignature
            || !str_starts_with($sourcePath, '/') || !str_starts_with($outputDirectory, '/')
            || !in_array($mode, ['encode', 'copy'], true)
            || ($mode === 'encode' && ($bitrate === null || $bitrate < 1 || $streamIndex !== null || $outputContainer !== null || $sourceHash !== null))
            || ($mode === 'copy' && ($bitrate !== null || $format !== AudioRenditionFormat::Aac || $streamIndex === null || $streamIndex < 0 || $outputContainer !== 'mp4'
                || $sourceHash === null || preg_match('/^[a-f0-9]{16}$/D', $sourceHash) !== 1))
        ) {
            throw new \InvalidArgumentException('Audio rendition requires a canonical source and absolute paths.');
        }
    }

    public static function copySourceSignature(string $sourcePath, string $sourceHash, int $streamIndex, int $modified, int $size): string
    {
        return 'copy-aac-mp4-' . $streamIndex . '-' . hash('sha256', json_encode([$sourcePath, $sourceHash, $streamIndex, $modified, $size], JSON_THROW_ON_ERROR)) . '.m4a';
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->trackKey, $this->sourceSignature, $this->mode, $this->format->value, $this->bitrate, $this->streamIndex, $this->outputContainer, $this->sourceHash], JSON_THROW_ON_ERROR));
    }
}
