<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\Actor;

/**
 * Who is signed in to the request being handled, for the actor that logs and audit fields
 * record. A console command or a message handler has nobody signed in and records an
 * {@see Actor} instead.
 */
interface SignedInUserInterface
{
    /** The signed-in user's ID, or null when nobody is signed in. */
    public function id(): ?string;
}
