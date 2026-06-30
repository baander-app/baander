<?php

declare(strict_types=1);

namespace App\Metadata\Application;

final readonly class EnrichmentResult
{
    /**
     * @param string[] $updatedFields
     */
    public function __construct(
        private bool $success,
        private string $source,
        private float $qualityScore,
        private array $updatedFields = [],
        private bool $identifiersUpdated = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getQualityScore(): float
    {
        return $this->qualityScore;
    }

    /**
     * @return string[]
     */
    public function getUpdatedFields(): array
    {
        return $this->updatedFields;
    }

    public function hasIdentifiersUpdated(): bool
    {
        return $this->identifiersUpdated;
    }

    public static function noMatch(string $source, float $qualityScore): self
    {
        return new self(false, $source, $qualityScore);
    }

    public static function failure(string $source, float $qualityScore = 0.0): self
    {
        return new self(false, $source, $qualityScore);
    }
}
