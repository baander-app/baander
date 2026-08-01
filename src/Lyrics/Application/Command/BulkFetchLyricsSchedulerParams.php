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
        description: 'Delay in milliseconds between API calls to avoid rate limiting',
        nullable: true,
    )]
    public ?int $delayMs = 500;
}
