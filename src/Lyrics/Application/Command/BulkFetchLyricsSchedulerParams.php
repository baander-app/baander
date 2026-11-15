<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'BulkFetchLyricsSchedulerParams')]
class BulkFetchLyricsSchedulerParams
{
    #[OA\Property(
        type: 'integer',
        description: 'Maximum number of songs to process in one run',
        nullable: true,
    )]
    public ?int $limit = null;

    #[OA\Property(
        type: 'integer',
        description: 'Milliseconds between the queued fetches. A value below the default is raised to the default',
        nullable: true,
    )]
    public ?int $delayMs = BulkFetchLyricsCommand::DEFAULT_DELAY_MS;
}
