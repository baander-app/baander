<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

final readonly class HardwareTranscodeDiagnosticsReport
{
    /**
     * @param array<string, string> $sourceDecoders Source codec to resolved decoder.
     * @param array<string, string> $sampleCommands Quality tier to sample command.
     * @param list<array{string, string, string, string, string, string}> $acceleratorReference
     */
    public function __construct(
        public string $accelerator,
        public string $encoder,
        public string $decoder,
        public string $hwaccelMethod,
        public string $hwaccelDevice,
        public string $hwaccelOutputFormat,
        public bool $isHardware,
        public float $bitrateMultiplier,
        public string $hwaccelFlags,
        public string $decoderFlags,
        public array $sourceDecoders,
        public array $sampleCommands,
        public array $acceleratorReference,
    ) {
    }
}
