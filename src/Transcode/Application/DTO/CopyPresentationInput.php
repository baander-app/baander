<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CopyPresentationInput
{
    public function __construct(
        public Uuid $owner,
        public Uuid $video,
        public string $sourceHash,
        public int $videoStreamIndex,
        public ?int $audioStreamIndex,
        public string $container,
        public int $width,
        public int $height,
        public string $requestId,
    ) {
    }
}
