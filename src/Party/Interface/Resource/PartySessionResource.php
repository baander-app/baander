<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartySessionView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartySessionResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'hostUserId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Host user public ID'),
        new OA\Property(property: 'videoId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Video public ID'),
        new OA\Property(property: 'transcodeJobId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, description: 'Transcode job that the host\'s playback steers; null when none was named or the job has been deleted'),
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
        assert($source instanceof PartySessionView);
        $view = $source;
        $source = $view->model;

        return [
            'publicId' => $source->getPublicId()->toString(),
            'hostUserId' => $view->hostUserId->toString(),
            'videoId' => $view->videoId->toString(),
            'transcodeJobId' => $view->transcodeJobId?->toString(),
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
