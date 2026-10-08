<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/** Redis, which holds the async transport's stream, cannot be reached. */
final class AsyncTransportUnavailableException extends \RuntimeException
{
}
