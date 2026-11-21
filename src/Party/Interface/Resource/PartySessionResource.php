<?php

declare(strict_types=1);

namespace App\Party\Interface\Resource;

use App\Party\Application\DTO\PartySessionView;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'hostUserId' => new OA\Schema(
            description: 'Host user public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'videoId' => new OA\Schema(
            description: 'Video public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'transcodeJobId' => new OA\Schema(
            description: 'Transcode job that the host\'s playback steers; null when none was named or the job has been deleted',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'maxMembers' => new OA\Schema(description: 'Maximum members allowed', type: 'integer'),
        'playbackState' => new OA\Schema(
            description: 'Playback state',
            type: 'string',
            enum: ['playing', 'paused', 'stopped'],
        ),
        'wallClockPosition' => new OA\Schema(
            description: 'Wall clock position',
            type: 'number',
            nullable: true,
        ),
        'currentPosition' => new OA\Schema(
            description: 'Current playback position',
            type: 'number',
            nullable: true,
        ),
        'isActive' => new OA\Schema(description: 'Whether the session is active', type: 'boolean'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: [
        'publicId',
        'hostUserId',
        'videoId',
        'maxMembers',
        'playbackState',
        'isActive',
        'createdAt',
        'updatedAt',
    ],
    component: 'PartySessionResource',
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
