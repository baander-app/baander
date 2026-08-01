<?php

declare(strict_types=1);

namespace App\Discovery\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Human-readable pairing code for server discovery.
 *
 * Generates consonant-based codes (e.g., "BCDF-GHJK") matching the
 * RFC 8628 DeviceCode userCode pattern for consistency.
 */
final readonly class PairingCode implements Stringable, JsonSerializable
{
    private const string CHARS = 'BCDFGHJKLMNPQRSTVWXZ';
    private const int SEGMENT_LENGTH = 4;
    private const string SEPARATOR = '-';

    private string $value;

    public function __construct(?string $value = null)
    {
        if ($value !== null) {
            $normalized = strtoupper(trim($value));
            $this->validate($normalized);
            $this->value = $normalized;

            return;
        }

        $this->value = self::generateCode();
    }

    public static function generate(): self
    {
        return new self();
    }

    public static function fromString(string $code): self
    {
        return new self($code);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    private static function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::SEGMENT_LENGTH; $i++) {
            $code .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $code .= self::SEPARATOR;
        for ($i = 0; $i < self::SEGMENT_LENGTH; $i++) {
            $code .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }

        return $code;
    }

    private function validate(string $code): void
    {
        $pattern = '/^[' . self::CHARS . ']{4}' . preg_quote(self::SEPARATOR, '/') . '[' . self::CHARS . ']{4}$/';
        if (!preg_match($pattern, $code)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid pairing code. Expected format: XXXX-XXXX (consonants only).',
                $code,
            ));
        }
    }
}
