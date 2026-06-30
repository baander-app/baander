<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'SearchLyricsRequest',
    required: ['q'],
    properties: [
        new OA\Property(property: 'q', description: 'Search query for lyrics', type: 'string', example: 'Still Alive Portal'),
    ],
)]
final readonly class SearchLyricsRequest
{
    public function __construct(
        #[NotBlank(message: 'Search query is required.')]
        public string $q = '',
    ) {
    }
}
