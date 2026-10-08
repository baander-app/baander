<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Actors recorded in audit fields and logs when no signed-in user made the change.
 */
final class Actor
{
    /** A change made with a console command, which acts with full authority while nobody is signed in. */
    public const string CLI = 'cli';

    private function __construct()
    {
    }
}
