<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use InvalidArgumentException;
use Stringable;

/**
 * Value object representing a client fingerprint.
 *
 * Fingerprints are SHA-256 hex strings (64 characters) used to bind
 * an OAuth token to the device or browser session that requested it.
 * This enables detection of token theft when the fingerprint changes.
 */
final readonly class ClientFingerprint implements Stringable
{
    private const HEX_LENGTH = 64;
    private const HEX_PATTERN = '/^[a-f0-9]{64}$/i';

    private string $hash;

    private function __construct(string $hash)
    {
        $normalized = strtolower($hash);

        if (strlen($normalized) !== self::HEX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Client fingerprint must be a %d-character SHA-256 hex string, got %d characters.',
                self::HEX_LENGTH,
                strlen($normalized),
            ));
        }

        if (preg_match(self::HEX_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(
                'Client fingerprint must be a valid SHA-256 hex string (lowercase a-f, 0-9).',
            );
        }

        $this->hash = $normalized;
    }

    /**
     * Create a fingerprint from an existing SHA-256 hex string.
     */
    public static function fromString(string $hash): self
    {
        return new self($hash);
    }

    /**
     * Generate a fingerprint by hashing concatenated data with SHA-256.
     *
     * Useful for deriving a stable fingerprint from client-provided
     * signals such as user agent, screen resolution, and plugins.
     */
    public static function generate(string ...$data): self
    {
        $input = implode('|', $data);

        return new self(hash('sha256', $input));
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}
