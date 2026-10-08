<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use RuntimeException;

/** A server control operation could not be carried out: unknown operation, protocol failure or no answer. */
class ServerControlException extends RuntimeException
{
}
