<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

final readonly class QualityTier implements JsonSerializable
{
    public function __construct(
        public string $name,
        public int $height,
        public int $width,
        public int $videoBitrate,
        public int $maxBitrate,
        public int $bufferSize,
        public string $codec,
        public string $rfc6381Codec,
    ) {
    }

    public static function p360(): self
    {
        return new self(
            name: '360p',
            height: 360,
            width: 640,
            videoBitrate: 800_000,
            maxBitrate: 1_200_000,
            bufferSize: 1_600_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L93.B0',
        );
    }

    public static function p480(): self
    {
        return new self(
            name: '480p',
            height: 480,
            width: 854,
            videoBitrate: 1_400_000,
            maxBitrate: 2_100_000,
            bufferSize: 2_800_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L93.B0',
        );
    }

    public static function p720(): self
    {
        return new self(
            name: '720p',
            height: 720,
            width: 1280,
            videoBitrate: 2_800_000,
            maxBitrate: 4_200_000,
            bufferSize: 5_600_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L93.B0',
        );
    }

    public static function p1080(): self
    {
        return new self(
            name: '1080p',
            height: 1080,
            width: 1920,
            videoBitrate: 5_000_000,
            maxBitrate: 7_500_000,
            bufferSize: 10_000_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L120.B0',
        );
    }

    public static function p1440(): self
    {
        return new self(
            name: '1440p',
            height: 1440,
            width: 2560,
            videoBitrate: 10_000_000,
            maxBitrate: 15_000_000,
            bufferSize: 20_000_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L150.B0',
        );
    }

    public static function p4K(): self
    {
        return new self(
            name: '4K',
            height: 2160,
            width: 3840,
            videoBitrate: 20_000_000,
            maxBitrate: 30_000_000,
            bufferSize: 40_000_000,
            codec: 'hvc1',
            rfc6381Codec: 'hvc1.1.6.L186.B0',
        );
    }

    public static function fromString(string $name): self
    {
        return match (strtolower($name)) {
            '360p' => self::p360(),
            '480p' => self::p480(),
            '720p' => self::p720(),
            '1080p' => self::p1080(),
            '1440p' => self::p1440(),
            '4k', '2160p' => self::p4K(),
            default => throw new InvalidArgumentException(sprintf('Unknown quality tier: "%s".', $name)),
        };
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'height' => $this->height,
            'width' => $this->width,
            'videoBitrate' => $this->videoBitrate,
            'maxBitrate' => $this->maxBitrate,
            'bufferSize' => $this->bufferSize,
            'codec' => $this->codec,
            'rfc6381Codec' => $this->rfc6381Codec,
        ];
    }
}
