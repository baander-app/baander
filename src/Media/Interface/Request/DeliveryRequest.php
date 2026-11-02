<?php

declare(strict_types=1);

namespace App\Media\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DeliveryRequest
{
    /**
     * @param list<string> $containers
     * @param list<string> $videoCodecs
     * @param list<string> $audioCodecs
     * @param list<string> $transports
     * @param list<string> $pixelFormats
     */
    public function __construct(
        #[Assert\Choice(choices: ['track', 'video'])] public string $kind,
        #[Assert\NotBlank] public string $id,
        #[Assert\Count(min: 1, max: 32)] public array $containers,
        #[Assert\Count(max: 32)] public array $videoCodecs = [],
        #[Assert\Count(max: 32)] public array $audioCodecs = [],
        #[Assert\Positive] public ?int $maxWidth = null,
        #[Assert\Positive] public ?int $maxHeight = null,
        #[Assert\Positive] public ?int $maxAudioChannels = null,
        #[Assert\PositiveOrZero] public ?int $audioStreamIndex = null,
        public bool $subtitleBurnIn = false,
        public array $transports = ['file'],
        #[Assert\Range(min: 8, max: 16)] public int $maxBitDepth = 8,
        public bool $supportsHdr = false,
        #[Assert\Count(max: 32)] public array $pixelFormats = ['yuv420p', 'yuvj420p', 'nv12'],
        #[Assert\Uuid] public ?string $requestId = null,
    ) {
    }
}
