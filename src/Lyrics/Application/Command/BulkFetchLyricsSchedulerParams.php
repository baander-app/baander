<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use OpenApi\Spec as OA;

#[OA\Schema(type: 'object', component: 'BulkFetchLyricsSchedulerParams')]
class BulkFetchLyricsSchedulerParams
{
    #[OA\Property(
        property: 'limit',
        schema: new OA\Schema(
            description: 'Maximum number of songs to process in one run',
            type: 'integer',
            nullable: true,
            default: null,
        ),
    )]
    public ?int $limit = null;

    #[OA\Property(
        property: 'delayMs',
        schema: new OA\Schema(
            description: 'Milliseconds between the queued fetches. A value below the default is raised to the default',
            type: 'integer',
            nullable: true,
            default: BulkFetchLyricsCommand::DEFAULT_DELAY_MS,
        ),
    )]
    public ?int $delayMs = BulkFetchLyricsCommand::DEFAULT_DELAY_MS;
}
