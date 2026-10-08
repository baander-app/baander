<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

/** Thrown into a running console scan when SIGINT or SIGTERM arrives, so the scan unwinds as failed. */
final class LibraryScanInterrupted extends \RuntimeException
{
    public function __construct(public readonly int $signal)
    {
        parent::__construct(sprintf('Interrupted by signal %d.', $signal));
    }
}
