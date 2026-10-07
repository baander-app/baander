<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * A user code the signed-in user entered cannot be approved, denied or shown.
 */
final class DeviceUserCodeException extends RuntimeException
{
    public const string INVALID = 'invalid_user_code';
    public const string ALREADY_PROCESSED = 'device_already_processed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Unknown, malformed or expired. */
    public static function invalid(): self
    {
        return new self(self::INVALID, 'Invalid or expired user code.');
    }

    public static function alreadyProcessed(): self
    {
        return new self(self::ALREADY_PROCESSED, 'The device request was already approved or denied.');
    }
}
