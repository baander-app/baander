<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

/** Thrown into a running console delete when SIGINT or SIGTERM arrives, so the delete unwinds through its finally blocks. */
final class CatalogDeleteInterrupted extends \RuntimeException
{
    public function __construct(public readonly int $signal)
    {
        parent::__construct(sprintf('Interrupted by signal %d.', $signal));
    }
}
