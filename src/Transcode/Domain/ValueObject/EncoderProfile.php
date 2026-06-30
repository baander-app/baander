<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use JsonSerializable;

/**
 * Resolved hardware encoder configuration.
 *
 * Produced by HardwareCapabilitiesProber at boot. Carries all information
 * needed to build a complete hardware-aware FFmpeg command: hwaccel init
 * flags, decoder selection, output encoder, device path, and filter strategy.
 */
final readonly class EncoderProfile implements JsonSerializable
{
    public function __construct(
        public HardwareAccelerator $accelerator,
        public string $encoder,
        public string $decoder,
        public string $hwaccelMethod,
        public string $hwaccelDevice,
        public string $hwaccelOutputFormat,
    ) {
    }

    /**
     * Software-only profile (fallback / default).
     */
    public static function software(string $encoder = 'libx265'): self
    {
        return new self(
            accelerator: HardwareAccelerator::None,
            encoder: $encoder,
            decoder: '',
            hwaccelMethod: '',
            hwaccelDevice: '',
            hwaccelOutputFormat: '',
        );
    }

    /**
     * Create from an encoder name with auto-detected accelerator.
     */
    public static function fromEncoderName(string $encoder, string $devicePath = ''): self
    {
        $accel = HardwareAccelerator::fromEncoderName($encoder);

        if ($accel === HardwareAccelerator::None) {
            return self::software($encoder);
        }

        return new self(
            accelerator: $accel,
            encoder: $encoder,
            decoder: '', // Resolved per-source at encode time
            hwaccelMethod: $accel->ffmpegHwaccelMethod(),
            hwaccelDevice: $devicePath ?: $accel->defaultDevicePath(),
            hwaccelOutputFormat: $accel->hwaccelOutputFormat(),
        );
    }

    /**
     * Whether hardware acceleration is active.
     */
    public function isHardware(): bool
    {
        return $this->accelerator !== HardwareAccelerator::None;
    }

    /**
     * Build FFmpeg hwaccel input flags (placed before -i).
     */
    public function hwaccelInputFlags(): string
    {
        if (!$this->isHardware()) {
            return '';
        }

        $flags = sprintf('-hwaccel %s', $this->hwaccelMethod);

        if ($this->hwaccelDevice !== '') {
            $flags .= sprintf(' -hwaccel_device %s', escapeshellarg($this->hwaccelDevice));
        }

        if ($this->hwaccelOutputFormat !== '') {
            $flags .= sprintf(' -hwaccel_output_format %s', $this->hwaccelOutputFormat);
        }

        return $flags;
    }

    /**
     * Build FFmpeg decoder input flag (placed before -i, after hwaccel flags).
     */
    public function decoderFlags(): string
    {
        if ($this->decoder === '') {
            return '';
        }

        return sprintf('-c:v %s', $this->decoder);
    }

    /**
     * Resolve decoder for a specific source codec.
     */
    public function withDecoderForSource(string $sourceCodec): self
    {
        $decoder = $this->accelerator->decoderForCodec($sourceCodec);

        return new self(
            accelerator: $this->accelerator,
            encoder: $this->encoder,
            decoder: $decoder,
            hwaccelMethod: $this->hwaccelMethod,
            hwaccelDevice: $this->hwaccelDevice,
            hwaccelOutputFormat: $this->hwaccelOutputFormat,
        );
    }

    /**
     * @return array{accelerator: string, encoder: string, decoder: string, hwaccelMethod: string, hwaccelDevice: string, hwaccelOutputFormat: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'accelerator' => $this->accelerator->value,
            'encoder' => $this->encoder,
            'decoder' => $this->decoder,
            'hwaccelMethod' => $this->hwaccelMethod,
            'hwaccelDevice' => $this->hwaccelDevice,
            'hwaccelOutputFormat' => $this->hwaccelOutputFormat,
        ];
    }

    /**
     * Reconstruct from serialized array (JSON payload or cached state).
     *
     * @param array{accelerator?: string, encoder?: string, decoder?: string, hwaccelMethod?: string, hwaccelDevice?: string, hwaccelOutputFormat?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accelerator: HardwareAccelerator::from((string) ($data['accelerator'] ?? 'none')),
            encoder: (string) ($data['encoder'] ?? 'libx265'),
            decoder: (string) ($data['decoder'] ?? ''),
            hwaccelMethod: (string) ($data['hwaccelMethod'] ?? ''),
            hwaccelDevice: (string) ($data['hwaccelDevice'] ?? ''),
            hwaccelOutputFormat: (string) ($data['hwaccelOutputFormat'] ?? ''),
        );
    }

    public function equals(self $other): bool
    {
        return $this->accelerator === $other->accelerator
            && $this->encoder === $other->encoder
            && $this->hwaccelDevice === $other->hwaccelDevice;
    }

    /**
     * Hardware identity fingerprint for change detection.
     * Combines accelerator + encoder so hardware swaps trigger learning reset.
     */
    public function getName(): string
    {
        return sprintf('%s/%s', $this->accelerator->value, $this->encoder);
    }
}
