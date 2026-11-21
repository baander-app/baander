<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Request;

use OpenApi\Spec as OA;

/**
 * The query string of GET /api/lyrics/search. SearchLyricsQuery rejects a blank query, so the
 * API and app:lyrics:search answer it the same way.
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'q' => new OA\Schema(
            description: 'Search query for lyrics',
            type: 'string',
            example: 'Still Alive Portal',
            default: '',
        ),
    ],
    required: ['q'],
    component: 'SearchLyricsRequest',
)]
final readonly class SearchLyricsRequest
{
    public function __construct(
        public string $q = '',
    ) {
    }
}
