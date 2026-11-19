<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/**
 * An operator marks a user's current email address verified, from the admin panel or
 * `app:user:mark-email-verified`, for example on an install that sends no email.
 */
final readonly class MarkEmailVerifiedCommand
{
    public function __construct(
        /** The user's email address or public ID. */
        public string $identifier,
    ) {
    }
}
