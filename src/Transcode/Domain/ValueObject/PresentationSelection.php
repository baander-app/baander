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
    ) {
        if (!in_array($mode, ['copy', 'encode'], true) || $sourceHash === '' || $outputContainer === ''
            || ($videoStreamIndex === null && $audioStreamIndex === null)
            || ($videoStreamIndex !== null && $videoStreamIndex < 0)
            || ($audioStreamIndex !== null && $audioStreamIndex < 0)
            || ($mode === 'encode' && ($encodingFingerprint === null || $encodingFingerprint === ''))
            || ($mode === 'copy' && $encodingFingerprint !== null)
        ) {
            throw new \InvalidArgumentException('Invalid media presentation selection.');
        }
    }

    public function canonicalKey(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR)
            |> (static fn (string $json): string => hash('sha256', $json));
    }

    /** @return array{mode: string, sourceHash: string, videoStreamIndex: ?int, audioStreamIndex: ?int, outputContainer: string, encodingFingerprint: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode,
            'sourceHash' => $this->sourceHash,
            'videoStreamIndex' => $this->videoStreamIndex,
            'audioStreamIndex' => $this->audioStreamIndex,
            'outputContainer' => $this->outputContainer,
            'encodingFingerprint' => $this->encodingFingerprint,
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
        if (!is_string($mode) || !is_string($hash) || !is_string($container)
            || ($video !== null && !is_int($video)) || ($audio !== null && !is_int($audio))
            || ($fingerprint !== null && !is_string($fingerprint))
        ) {
            throw new \InvalidArgumentException('Invalid persisted media presentation selection.');
        }
        return new self($mode, $hash, $video, $audio, $container, $fingerprint);
    }
}
