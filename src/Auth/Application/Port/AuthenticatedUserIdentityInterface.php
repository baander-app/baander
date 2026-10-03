<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

/** Application-facing identity supplied by an authenticated security principal. */
interface AuthenticatedUserIdentityInterface
{
    /** The authenticated user's UUID string. */
    public function getId(): string;
}
