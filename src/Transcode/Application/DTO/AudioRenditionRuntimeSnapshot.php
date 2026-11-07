<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Domain\ValueObject\AacImplementation;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioRenditionRuntimeSnapshot
{
    public function __construct(
        public string $key,
        public AudioRenditionInput $input,
        public string $state,
        public int $generation,
        public ?string $partialPath,
        public ?string $finalPath,
        public ?string $error,
        public bool $ownershipLive = false,
        /** The AAC implementation a failed encode used; null when it used none or has not failed. */
        public ?AacImplementation $failedAacImplementation = null,
    ) {}

    public function generationDirectory(): string
    {
        return rtrim($this->input->outputDirectory, '/') . '/generation-' . $this->generation;
    }
}
