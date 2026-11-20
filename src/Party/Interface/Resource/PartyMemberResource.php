<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartyMemberView;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartyMemberResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'userId', description: 'User public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'sessionId', description: 'Session public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'role', description: 'Member role', type: 'string', enum: ['host', 'member']),
        new OA\Property(property: 'audioProfileId', description: 'Playback audio profile token', type: 'string', nullable: true),
        new OA\Property(property: 'subtitleTrackId', description: 'Subtitle track ID', type: 'string', nullable: true),
        new OA\Property(property: 'lastSyncPosition', description: 'Last sync position', type: 'number', nullable: true),
        new OA\Property(property: 'jitterCompensation', description: 'Jitter compensation', type: 'number', nullable: true),
        new OA\Property(property: 'isConnected', description: 'Whether the member is connected', type: 'boolean'),
        new OA\Property(property: 'joinedAt', description: 'Join timestamp', type: 'string', format: 'date-time'),
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
