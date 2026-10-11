<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Whether the web server is draining: a stop has begun, and the server finishes its work before
 * it exits. A worker reload is not a drain. Outside the web server nothing is draining.
 */
interface ServerDrainStateInterface
{
    public function isDraining(): bool;
}
