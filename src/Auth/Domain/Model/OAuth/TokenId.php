<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use Hidehalo\Nanoid\Client;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Value object for a unique OAuth token identifier.
 *
 * Token IDs are URL-safe random strings used to identify access tokens,
 * refresh tokens, and authorization codes. They are separate from the
 * internal UUID primary key, which is never exposed to clients.
 */
final readonly class TokenId implements Stringable, JsonSerializable
{
    private const LENGTH = 80;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    private string $value;

    public function __construct(?string $value = null)
    {
        if ($value === null) {
            $client = new Client();
            $this->value = $client->generateId(self::LENGTH, self::ALPHABET);

            return;
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('Token ID cannot be empty.');
        }

        if (strlen($value) < 32) {
            throw new InvalidArgumentException(sprintf(
                'Token ID must be at least 32 characters long, got %d.',
                strlen($value),
            ));
        }

        $this->value = $value;
    }

    public static function generate(): self
    {
        return new self();
    }

    public static function fromString(string $value): self
    {
        return new self($value);
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
}
