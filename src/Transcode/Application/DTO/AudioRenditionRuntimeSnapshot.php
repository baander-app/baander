<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

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
    ) {}

    public function generationDirectory(): string
    {
        return rtrim($this->input->outputDirectory, '/') . '/generation-' . $this->generation;
    }
}
