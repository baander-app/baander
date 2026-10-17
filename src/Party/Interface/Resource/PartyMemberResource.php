<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyMemberView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartyMemberResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'User public ID'),
        new OA\Property(property: 'sessionId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Session public ID'),
        new OA\Property(property: 'role', type: 'string', enum: ['host', 'member'], description: 'Member role'),
        new OA\Property(property: 'audioProfileId', type: 'string', nullable: true, description: 'Playback audio profile token'),
        new OA\Property(property: 'subtitleTrackId', type: 'string', nullable: true, description: 'Subtitle track ID'),
        new OA\Property(property: 'lastSyncPosition', type: 'number', nullable: true, description: 'Last sync position'),
        new OA\Property(property: 'jitterCompensation', type: 'number', nullable: true, description: 'Jitter compensation'),
        new OA\Property(property: 'isConnected', type: 'boolean', description: 'Whether the member is connected'),
        new OA\Property(property: 'joinedAt', type: 'string', format: 'date-time', description: 'Join timestamp'),
    ],
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
