<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartySessionView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartySessionResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'hostUserId', description: 'Host user public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'videoId', description: 'Video public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'transcodeJobId', description: 'Transcode job that the host\'s playback steers; null when none was named or the job has been deleted', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'maxMembers', description: 'Maximum members allowed', type: 'integer'),
        new OA\Property(property: 'playbackState', description: 'Playback state', type: 'string', enum: ['playing', 'paused', 'stopped']),
        new OA\Property(property: 'wallClockPosition', description: 'Wall clock position', type: 'number', nullable: true),
        new OA\Property(property: 'currentPosition', description: 'Current playback position', type: 'number', nullable: true),
        new OA\Property(property: 'isActive', description: 'Whether the session is active', type: 'boolean'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
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
