<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PartyMemberResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Member UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', description: 'User UUID'),
        new OA\Property(property: 'sessionId', type: 'string', format: 'uuid', description: 'Session UUID'),
        new OA\Property(property: 'role', type: 'string', enum: ['host', 'member'], description: 'Member role'),
        new OA\Property(property: 'audioProfileId', type: 'string', format: 'uuid', nullable: true, description: 'Audio profile UUID'),
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
        assert($source instanceof PartyMember);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $source->getUserId()->toString(),
            'sessionId' => $source->getSessionId()->toString(),
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
