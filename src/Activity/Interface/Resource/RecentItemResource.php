<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

/**
 * Sidebar-optimized recent item shape.
 * Omits internal fields (uuid, userId, createdAt, updatedAt, lastPlatform, lastPlayer).
 */
#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'activityType' => new OA\Schema(description: 'Type of activity', type: 'string'),
        'songAvailable' => new OA\Schema(
            description: 'Whether the latest scan or ingestion observation marked the song source available',
            type: 'boolean',
        ),
        'songTitle' => new OA\Schema(
            description: 'Song title',
            type: 'string',
            nullable: true,
        ),
        'songPublicId' => new OA\Schema(
            description: 'Song public identifier',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'albumTitle' => new OA\Schema(
            description: 'Album title',
            type: 'string',
            nullable: true,
        ),
        'albumPublicId' => new OA\Schema(
            description: 'Album public identifier',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'artistName' => new OA\Schema(
            description: 'Artist name',
            type: 'string',
            nullable: true,
        ),
        'coverImage' => new OA\Schema(
            description: 'Cover image with url and blurhash',
            type: 'object',
            nullable: true,
        ),
        'lastPlayedAt' => new OA\Schema(
            description: 'Last play timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'playCount' => new OA\Schema(description: 'Number of plays', type: 'integer'),
    ],
    required: ['publicId', 'activityType', 'songAvailable', 'playCount'],
    component: 'RecentItemResource',
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
