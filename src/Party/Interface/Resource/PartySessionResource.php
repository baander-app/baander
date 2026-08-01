<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use App\Party\Domain\Model\SyncedPartySession;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartySessionResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Session UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'hostUserId', type: 'string', format: 'uuid', description: 'Host user UUID'),
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid', description: 'Video UUID'),
        new OA\Property(property: 'transcodeJobId', type: 'string', format: 'uuid', description: 'Transcode job UUID'),
        new OA\Property(property: 'maxMembers', type: 'integer', description: 'Maximum members allowed'),
        new OA\Property(property: 'playbackState', type: 'string', enum: ['playing', 'paused', 'stopped'], description: 'Playback state'),
        new OA\Property(property: 'wallClockPosition', type: 'number', nullable: true, description: 'Wall clock position'),
        new OA\Property(property: 'currentPosition', type: 'number', nullable: true, description: 'Current playback position'),
        new OA\Property(property: 'isActive', type: 'boolean', description: 'Whether the session is active'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class PartySessionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof SyncedPartySession);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'hostUserId' => $source->getHostUserId()->toString(),
            'videoId' => $source->getVideoId()->toString(),
            'transcodeJobId' => $source->getTranscodeJobId()->toString(),
            'maxMembers' => $source->getMaxMembers(),
            'playbackState' => $source->getPlaybackState()->value,
            'wallClockPosition' => $source->getWallClockPosition(),
            'currentPosition' => $source->getCurrentPosition(),
            'isActive' => $source->isActive(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
