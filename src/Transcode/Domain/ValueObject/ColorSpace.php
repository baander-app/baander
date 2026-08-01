<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use JsonSerializable;

final readonly class ColorSpace implements JsonSerializable
{
    private const HDR_PRIMARIES = ['bt2020', 'smpte2084'];
    private const HDR_TRANSFERS = ['smpte2084', 'arib-std-b67', 'pq', 'hlg'];

    public function __construct(
        public string $primaries,
        public string $transfer,
        public string $matrix,
    ) {
    }

    public static function bt709(): self
    {
        return new self(
            primaries: 'bt709',
            transfer: 'bt709',
            matrix: 'bt709',
        );
    }

    public static function bt2020Pq(): self
    {
        return new self(
            primaries: 'bt2020',
            transfer: 'smpte2084',
            matrix: 'bt2020nc',
        );
    }

    public static function bt2020Hlg(): self
    {
        return new self(
            primaries: 'bt2020',
            transfer: 'arib-std-b67',
            matrix: 'bt2020nc',
        );
    }

    /**
     * Construct from raw ffprobe output values.
     */
    public static function fromProbeValues(
        ?string $primaries,
        ?string $transfer,
        ?string $matrix,
    ): self {
        return new self(
            primaries: strtolower($primaries ?? 'bt709'),
            transfer: strtolower($transfer ?? 'bt709'),
            matrix: strtolower($matrix ?? 'bt709'),
        );
    }

    /**
     * Construct from a serialized array (jsonSerialize output).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            primaries: $data['primaries'] ?? 'bt709',
            transfer: $data['transfer'] ?? 'bt709',
            matrix: $data['matrix'] ?? 'bt709',
        );
    }

    public function isHdr(): bool
    {
        return in_array($this->primaries, self::HDR_PRIMARIES, true)
            || in_array($this->transfer, self::HDR_TRANSFERS, true);
    }

    public function equals(self $other): bool
    {
        return $this->primaries === $other->primaries
            && $this->transfer === $other->transfer
            && $this->matrix === $other->matrix;
    }

    public function jsonSerialize(): array
    {
        return [
            'primaries' => $this->primaries,
            'transfer' => $this->transfer,
            'matrix' => $this->matrix,
        ];
    }
}
