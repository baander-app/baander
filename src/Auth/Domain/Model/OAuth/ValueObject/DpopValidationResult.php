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
        private bool $replayUnchecked,
    ) {
    }

    /**
     * @param bool $replayUnchecked The jti store could not answer and the caller allowed the
     *     replay check to be skipped; every other check passed.
     */
    public static function valid(string $jkt, bool $replayUnchecked = false): self
    {
        return new self(valid: true, jkt: $jkt, error: null, errorDescription: null, replayUnchecked: $replayUnchecked);
    }

    public static function invalid(string $error, string $description): self
    {
        return new self(valid: false, jkt: null, error: $error, errorDescription: $description, replayUnchecked: false);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /** True only for a valid result whose replay check did not run because the jti store was unavailable. */
    public function isReplayUnchecked(): bool
    {
        return $this->replayUnchecked;
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
