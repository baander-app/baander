<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyMemberView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'userId' => new OA\Schema(
            description: 'User public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'sessionId' => new OA\Schema(
            description: 'Session public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'role' => new OA\Schema(
            description: 'Member role',
            type: 'string',
            enum: ['host', 'member'],
        ),
        'audioProfileId' => new OA\Schema(
            description: 'Playback audio profile token',
            type: 'string',
            nullable: true,
        ),
        'subtitleTrackId' => new OA\Schema(
            description: 'Subtitle track ID',
            type: 'string',
            nullable: true,
        ),
        'lastSyncPosition' => new OA\Schema(
            description: 'Last sync position',
            type: 'number',
            nullable: true,
        ),
        'jitterCompensation' => new OA\Schema(
            description: 'Jitter compensation',
            type: 'number',
            nullable: true,
        ),
        'isConnected' => new OA\Schema(description: 'Whether the member is connected', type: 'boolean'),
        'joinedAt' => new OA\Schema(
            description: 'Join timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    component: 'PartyMemberResource',
)]
final class PartyMemberResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PartyMemberView);
        $view = $source;
        $source = $view->model;

        return [
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $view->userId->toString(),
            'sessionId' => $view->sessionId->toString(),
            'role' => $source->getRole()->value,
            'audioProfileId' => $source->getAudioProfileId(),
            'subtitleTrackId' => $source->getSubtitleTrackId(),
            'lastSyncPosition' => $source->getLastSyncPosition(),
            'jitterCompensation' => $source->getJitterCompensation(),
            'isConnected' => $source->isConnected(),
            'joinedAt' => $source->getJoinedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
