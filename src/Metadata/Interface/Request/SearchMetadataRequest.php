<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Range;

/**
 * The query string of the GET /api/metadata/search routes. A blank query keeps its 400 answer
 * in the controller; a result count outside 1 to 100 answers 422 before any provider is called.
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'q' => new OA\Schema(
            description: 'Search query',
            type: 'string',
            default: '',
        ),
        'source' => new OA\Schema(
            description: 'Data source',
            type: 'string',
            default: 'musicbrainz',
        ),
        'artist' => new OA\Schema(
            description: 'Filter by artist name',
            type: 'string',
            nullable: true,
        ),
        'limit' => new OA\Schema(
            description: 'Maximum number of results',
            type: 'integer',
            minimum: 1,
            maximum: 100,
            default: 25,
        ),
    ],
    required: ['q'],
    component: 'SearchMetadataRequest',
)]
final readonly class SearchMetadataRequest
{
    public function __construct(
        public string $q = '',
        public string $source = 'musicbrainz',
        public ?string $artist = null,
        #[Range(min: 1, max: 100)]
        public int $limit = 25,
    ) {
    }
}
