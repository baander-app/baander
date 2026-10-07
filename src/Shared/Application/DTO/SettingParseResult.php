<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use App\Shared\Domain\Model\Setting\SettingViolation;

/**
 * A typed setting value, or the violation that rejected the input.
 */
final readonly class SettingParseResult
{
    private function __construct(
        public bool|int|string|null $value,
        public ?SettingViolation $violation,
    ) {
    }

    public static function valid(bool|int|string $value): self
    {
        return new self($value, null);
    }

    public static function invalid(string $key, string $message): self
    {
        return new self(null, new SettingViolation($key, $message));
    }

    public function isValid(): bool
    {
        return $this->violation === null;
    }
}
