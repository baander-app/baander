<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\FailureTransportUnavailableException;

/**
 * Reads the health of the Messenger transports: the async stream's depth, whether
 * the configured consumer is registered on it, and the failure transport's count.
 * The admin status endpoint and app:monitor:transport both read it here.
 */
interface TransportStatusInterface
{
    /**
     * @throws AsyncTransportUnavailableException when Redis cannot be reached
     * @throws FailureTransportUnavailableException when the failure transport cannot be read
     */
    public function status(): TransportStatus;
}
