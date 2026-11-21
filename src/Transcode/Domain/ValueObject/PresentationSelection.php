<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

final readonly class PresentationSelection implements \JsonSerializable
{
    public function __construct(
        public string $mode,
        public string $sourceHash,
        public ?int $videoStreamIndex,
        public ?int $audioStreamIndex,
        public string $outputContainer,
        public ?string $encodingFingerprint = null,
        public int $audioOffsetMs = 0,
    ) {
        if (
            !in_array($mode, ['copy', 'encode'], true) || $sourceHash === '' || $outputContainer === ''
            || ($videoStreamIndex === null && $audioStreamIndex === null)
            || ($videoStreamIndex !== null && $videoStreamIndex < 0)
            || ($audioStreamIndex !== null && $audioStreamIndex < 0)
            || ($mode === 'encode' && ($encodingFingerprint === null || $encodingFingerprint === ''))
            || $audioOffsetMs < -10000 || $audioOffsetMs > 10000
            || ($audioOffsetMs !== 0 && ($mode !== 'encode' || $audioStreamIndex === null))
            || ($mode === 'copy' && $encodingFingerprint !== null)

        ) {
            throw new \InvalidArgumentException('Invalid media presentation selection.');
        }
    }

    public function canonicalKey(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR)
            |> (static fn(string $json): string => hash('sha256', $json));
    }

    /** @return array{mode: string, sourceHash: string, videoStreamIndex: ?int, audioStreamIndex: ?int, outputContainer: string, encodingFingerprint: ?string, audioOffsetMs: int} */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode,
            'sourceHash' => $this->sourceHash,
            'videoStreamIndex' => $this->videoStreamIndex,
            'audioStreamIndex' => $this->audioStreamIndex,
            'outputContainer' => $this->outputContainer,
            'encodingFingerprint' => $this->encodingFingerprint,
            'audioOffsetMs' => $this->audioOffsetMs,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $mode = $data['mode'] ?? null;
        $hash = $data['sourceHash'] ?? null;
        $video = $data['videoStreamIndex'] ?? null;
        $audio = $data['audioStreamIndex'] ?? null;
        $container = $data['outputContainer'] ?? null;
        $fingerprint = $data['encodingFingerprint'] ?? null;
        $offset = array_key_exists('audioOffsetMs', $data) ? $data['audioOffsetMs'] : 0;
        if (
            !is_int($offset) || !is_string($mode) || !is_string($hash) || !is_string($container)
            || ($video !== null && !is_int($video)) || ($audio !== null && !is_int($audio))
            || ($fingerprint !== null && !is_string($fingerprint))

        ) {
            throw new \InvalidArgumentException('Invalid persisted media presentation selection.');
        }
        return new self($mode, $hash, $video, $audio, $container, $fingerprint, $offset);
    }
}
