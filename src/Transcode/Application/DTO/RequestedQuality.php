<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Domain\ValueObject\QualityTier;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RequestedQuality
{
    private function __construct(
        public string $id,
        public int $width,
        public int $height,
    ) {}

    public static function fromString(string $value): self
    {
        $tier = QualityTier::fromString($value);

        return new self($tier->name, $tier->width, $tier->height);
    }
}
