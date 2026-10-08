<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\Port\TransportStatus;

/** The `data` of GET /api/monitor/transport/status and of `app:monitor:transport --json`. */
final class TransportStatusResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TransportStatus);

        return [
            'asyncQueueDepth' => $source->asyncQueueDepth,
            'failedQueueDepth' => $source->failedQueueDepth,
            'consumerName' => $source->consumerName,
            'consumerRunning' => $source->consumerRunning,
        ];
    }
}
