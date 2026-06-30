<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

final readonly class DpopValidationResult
{
    private function __construct(
        private bool $valid,
        private ?string $jkt,
        private ?string $error,
        private ?string $errorDescription,
    ) {
    }

    public static function valid(string $jkt): self
    {
        return new self(valid: true, jkt: $jkt, error: null, errorDescription: null);
    }

    public static function invalid(string $error, string $description): self
    {
        return new self(valid: false, jkt: null, error: $error, errorDescription: $description);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getJkt(): ?string
    {
        return $this->jkt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorDescription(): ?string
    {
        return $this->errorDescription;
    }
}
