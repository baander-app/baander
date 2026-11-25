<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

use App\Transcode\Application\DTO\RequestedQuality;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class DeliveryDestination
{
    public ?string $qualityTier;

    /**
     * @param list<string> $containers
     * @param list<string> $videoCodecs
     * @param list<string> $audioCodecs
     * @param list<string> $transports
     * @param list<string> $pixelFormats
     */
    public function __construct(
        public array $containers,
        public array $videoCodecs = [],
        public array $audioCodecs = [],
        public ?int $maxWidth = null,
        public ?int $maxHeight = null,
        public ?int $maxAudioChannels = null,
        public ?int $audioStreamIndex = null,
        public bool $subtitleBurnIn = false,
        public array $transports = ['file'],
        public int $maxBitDepth = 8,
        public bool $supportsHdr = false,
        public array $pixelFormats = ['yuv420p', 'yuvj420p', 'nv12'],
        public bool $sourcePlaybackFailed = false,
        public int $audioOffsetMs = 0,
        ?string $qualityTier = null,
    ) {
        $this->qualityTier = $qualityTier === null ? null : RequestedQuality::fromString($qualityTier)->id;

        if ($audioOffsetMs < -10000 || $audioOffsetMs > 10000) {
            throw new \InvalidArgumentException('Audio offset must be between -10000 and 10000 milliseconds.');
        }
        if (
            $transports === []
            || array_diff($transports, ['file', 'hls', 'dash']) !== []
            || $maxBitDepth < 8
            || $maxBitDepth > 16
        ) {
            throw new \InvalidArgumentException('Invalid destination transport or bit depth.');
        }
        if ($containers === [] || count($containers) > 32 || count($videoCodecs) > 32 || count($audioCodecs) > 32) {
            throw new \InvalidArgumentException('Destination must specify bounded media capabilities.');
        }
        foreach ([...$containers, ...$videoCodecs, ...$audioCodecs, ...$pixelFormats] as $value) {
            if (!self::validCapability($value)) {
                throw new \InvalidArgumentException('Media capabilities must use canonical lowercase codec and container names.');
            }
        }
        foreach ([$maxWidth, $maxHeight, $maxAudioChannels] as $limit) {
            if ($limit !== null && $limit < 1) {
                throw new \InvalidArgumentException('Media limits must be positive.');
            }
        }
        if ($audioStreamIndex !== null && $audioStreamIndex < 0) {
            throw new \InvalidArgumentException('Stream index cannot be negative.');
        }
    }

    private static function validCapability(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $value) === 1;
    }
}
