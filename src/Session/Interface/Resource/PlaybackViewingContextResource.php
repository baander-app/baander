<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'titleKind' => new OA\Schema(type: 'string', enum: ['episode', 'movie', 'extra']),
        'titleId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
    ],
    required: ['titleKind', 'titleId'],
    component: 'PlaybackViewingContextResource',
)]
final class PlaybackViewingContextResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return ['titleKind' => $source['titleKind'], 'titleId' => $source['titleId']];
    }
}
