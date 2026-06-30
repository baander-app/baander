<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use InvalidArgumentException;
use Stringable;

/**
 * Represents a single OAuth 2.0 scope string.
 *
 * Scopes are immutable value objects that identify the level of access
 * granted by a token. They follow a simple string format: `scope-name`.
 */
final readonly class Scope implements Stringable
{
    private const int MAX_LENGTH = 64;
    private const string PATTERN = '/^[a-z0-9][a-z0-9\-\.]*[a-z0-9]$|^[a-z0-9]$/';

    private string $scope;

    public function __construct(string $scope)
    {
        $normalized = strtolower(trim($scope));

        if ($normalized === '') {
            throw new InvalidArgumentException('Scope cannot be empty.');
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Scope must not exceed %d characters, got %d.',
                self::MAX_LENGTH,
                strlen($normalized),
            ));
        }

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Scope "%s" contains invalid characters. Only lowercase alphanumeric, hyphens, and dots are allowed.',
                $normalized,
            ));
        }

        $this->scope = $normalized;
    }

    public static function fromString(string $scope): self
    {
        return new self($scope);
    }

    /**
     * Returns the commonly used built-in scopes.
     *
     * @return self[]
     */
    public static function defaultScopes(): array
    {
        return [
            self::accessApi(),
        ];
    }

    public static function accessApi(): self
    {
        return new self('access-api');
    }

    public static function profile(): self
    {
        return new self('profile');
    }

    public static function admin(): self
    {
        return new self('admin');
    }

    public function toString(): string
    {
        return $this->scope;
    }

    public function equals(self $other): bool
    {
        return $this->scope === $other->scope;
    }

    public function __toString(): string
    {
        return $this->scope;
    }
}
