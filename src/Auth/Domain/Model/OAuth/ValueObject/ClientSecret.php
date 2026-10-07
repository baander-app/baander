<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use InvalidArgumentException;

/**
 * A confidential OAuth client's secret in plain text, held only between its
 * generation and the single response that shows it.
 *
 * The client stores the SHA-256 digest alone. A generated secret carries 256
 * random bits, so a slow password hash would add no resistance to guessing,
 * only CPU cost to every token request that authenticates the client.
 */
final readonly class ClientSecret
{
    private const int RANDOM_BYTES = 32;

    private function __construct(
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('A client secret cannot be empty.');
        }
    }

    /** A new 256-bit secret, base64url-encoded without padding (43 characters). */
    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '='));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /** Lower-case hex SHA-256 digest of the secret, the only form that is stored. */
    public function hash(): string
    {
        return self::digest($this->value);
    }

    /** Compares a presented secret with a stored digest in constant time. */
    public static function matches(string $storedHash, string $presented): bool
    {
        return $presented !== '' && hash_equals($storedHash, self::digest($presented));
    }

    public function toString(): string
    {
        return $this->value;
    }

    private static function digest(string $value): string
    {
        return hash('sha256', $value);
    }
}
