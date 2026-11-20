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
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'activityType', description: 'Type of activity', type: 'string'),
        new OA\Property(property: 'songAvailable', description: 'Whether the latest scan or ingestion observation marked the song source available', type: 'boolean'),
        new OA\Property(property: 'songTitle', description: 'Song title', type: 'string', nullable: true),
        new OA\Property(property: 'songPublicId', description: 'Song public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'albumTitle', description: 'Album title', type: 'string', nullable: true),
        new OA\Property(property: 'albumPublicId', description: 'Album public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'artistName', description: 'Artist name', type: 'string', nullable: true),
        new OA\Property(property: 'coverImage', description: 'Cover image with url and blurhash', type: 'object', nullable: true),
        new OA\Property(property: 'lastPlayedAt', description: 'Last play timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'playCount', description: 'Number of plays', type: 'integer'),
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
