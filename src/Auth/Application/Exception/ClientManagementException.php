<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * An administrator's client registration, secret rotation or revocation cannot be applied.
 */
final class ClientManagementException extends RuntimeException
{
    public const string INVALID_REGISTRATION = 'invalid_registration';
    public const string PROTECTED_CLIENT = 'protected_client';
    public const string NO_SECRET = 'no_secret';
    public const string REVOKED = 'revoked';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** The name, type or redirect URIs are not acceptable; the message says why. */
    public static function invalidRegistration(string $message): self
    {
        return new self(self::INVALID_REGISTRATION, $message);
    }

    /** First-party and personal access clients are not managed through the administrator paths. */
    public static function protectedClient(): self
    {
        return new self(self::PROTECTED_CLIENT, 'The first-party and personal access clients cannot be changed here.');
    }

    public static function noSecret(): self
    {
        return new self(self::NO_SECRET, 'Only confidential clients have a secret.');
    }

    public static function revoked(): self
    {
        return new self(self::REVOKED, 'The client has been revoked.');
    }
}
