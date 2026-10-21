<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

/**
 * Sidebar-optimized recent item shape.
 * Omits internal fields (uuid, userId, createdAt, updatedAt, lastPlatform, lastPlayer).
 */
#[OA\Schema(
    schema: 'RecentItemResource',
    required: ['songAvailable'],
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'activityType', type: 'string', description: 'Type of activity'),
        new OA\Property(property: 'songAvailable', type: 'boolean', description: 'Whether the latest scan or ingestion observation marked the song source available'),
        new OA\Property(property: 'songTitle', type: 'string', nullable: true, description: 'Song title'),
        new OA\Property(property: 'songPublicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, description: 'Song public identifier'),
        new OA\Property(property: 'albumTitle', type: 'string', nullable: true, description: 'Album title'),
        new OA\Property(property: 'albumPublicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, description: 'Album public identifier'),
        new OA\Property(property: 'artistName', type: 'string', nullable: true, description: 'Artist name'),
        new OA\Property(property: 'coverImage', type: 'object', nullable: true, description: 'Cover image with url and blurhash'),
        new OA\Property(property: 'lastPlayedAt', type: 'string', format: 'date-time', nullable: true, description: 'Last play timestamp'),
        new OA\Property(property: 'playCount', type: 'integer', description: 'Number of plays'),
    ],
)]
final class RecentItemResource extends AbstractResource
{
    /**
     * @param array<string, mixed> $source  Enriched activity array from ActivityEnrichmentService
     * @return array<string, mixed>
     */
    public static function from(mixed $source): array
    {
        return [
            'publicId' => $source['publicId'],
            'activityType' => $source['activityType'],
            'songTitle' => $source['songTitle'] ?? null,
            'songAvailable' => $source['songAvailable'] ?? false,
            'songPublicId' => $source['songPublicId'] ?? null,
            'albumTitle' => $source['albumTitle'] ?? null,
            'albumPublicId' => $source['albumPublicId'] ?? null,
            'artistName' => $source['artistName'] ?? null,
            'coverImage' => $source['coverImage'] ?? null,
            'lastPlayedAt' => $source['lastPlayedAt'] ?? null,
            'playCount' => $source['playCount'] ?? 0,
        ];
    }
}
