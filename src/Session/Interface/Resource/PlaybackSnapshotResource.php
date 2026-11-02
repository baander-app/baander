<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackSnapshotResource',
    required: [
        'schemaVersion', 'publicId', 'revision', 'media', 'queue', 'currentTrackIndex',
        'position', 'playbackState', 'desiredState', 'observedState',
        'selectedDestinationId', 'activeDestinationId', 'activeIncarnationId',
        'transfer', 'destinations', 'commands',
    ],
    properties: [
        new OA\Property(property: 'schemaVersion', type: 'integer', enum: [1]),
        new OA\Property(property: 'publicId', type: 'string'),
        new OA\Property(property: 'revision', type: 'integer', minimum: 0),
        new OA\Property(property: 'media', ref: new Model(type: PlaybackMediaResource::class), nullable: true),
        new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0),
        new OA\Property(property: 'position', type: 'number', minimum: 0),
        new OA\Property(property: 'playbackState', type: 'string', enum: ['playing', 'paused', 'stopped']),
        new OA\Property(property: 'desiredState', type: 'string', enum: ['playing', 'paused', 'stopped']),
        new OA\Property(property: 'observedState', type: 'string', enum: ['playing', 'paused', 'stopped', 'unknown']),
        new OA\Property(property: 'selectedDestinationId', type: 'string', nullable: true),
        new OA\Property(property: 'activeDestinationId', type: 'string', nullable: true),
        new OA\Property(property: 'activeIncarnationId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'transfer', ref: new Model(type: PlaybackTransferResource::class), nullable: true),
        new OA\Property(property: 'destinations', type: 'array', items: new OA\Items(ref: new Model(type: PlaybackDestinationResource::class))),
        new OA\Property(property: 'commands', type: 'array', items: new OA\Items(ref: new Model(type: PlaybackCommandResource::class))),
        new OA\Property(property: 'endpoint', ref: new Model(type: PlaybackEndpointResource::class), nullable: true),
        new OA\Property(property: 'recoveryResult', required: ['incarnationId', 'outcome', 'revision'], properties: [
            new OA\Property(property: 'incarnationId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'outcome', type: 'string', enum: ['recovered']),
            new OA\Property(property: 'revision', type: 'integer', minimum: 0),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'transferResult', required: ['requestId', 'attemptId', 'state', 'rejectionReason'], properties: [
            new OA\Property(property: 'requestId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'attemptId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'state', type: 'string', enum: ['preparing', 'quiescing', 'starting', 'committed', 'failed', 'uncertain']),
            new OA\Property(property: 'rejectionReason', type: 'string', nullable: true),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'requestResult', required: ['requestId', 'commandId', 'outcome', 'rejectionReason'], properties: [
            new OA\Property(property: 'requestId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'commandId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'outcome', type: 'string', nullable: true),
            new OA\Property(property: 'rejectionReason', type: 'string', nullable: true),
        ], type: 'object', nullable: true),
    ],
)]
final class PlaybackSnapshotResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        $result = [
            'schemaVersion' => $source['schemaVersion'],
            'publicId' => $source['publicId'],
            'revision' => $source['revision'],
            'media' => $source['media'] === null ? null : PlaybackMediaResource::from($source['media']),
            'queue' => $source['queue'],
            'currentTrackIndex' => $source['currentTrackIndex'],
            'position' => $source['position'],
            'playbackState' => $source['playbackState'],
            'desiredState' => $source['desiredState'],
            'observedState' => $source['observedState'],
            'selectedDestinationId' => $source['selectedDestinationId'],
            'activeDestinationId' => $source['activeDestinationId'],
            'activeIncarnationId' => $source['activeIncarnationId'],
            'transfer' => $source['transfer'] === null ? null : PlaybackTransferResource::from($source['transfer']),
            'destinations' => PlaybackDestinationResource::collection($source['destinations']),
            'commands' => PlaybackCommandResource::collection($source['commands']),
        ];
        if (isset($source['endpoint'])) {
            $result['endpoint'] = PlaybackEndpointResource::from($source['endpoint']);
        }
        if (isset($source['requestResult'])) {
            $result['requestResult'] = [
                'requestId' => $source['requestResult']['requestId'],
                'commandId' => $source['requestResult']['commandId'],
                'outcome' => $source['requestResult']['outcome'],
                'rejectionReason' => $source['requestResult']['rejectionReason'] ?? null,
            ];
        }

        if (isset($source['transferResult'])) {
            $result['transferResult'] = [
                'requestId' => $source['transferResult']['requestId'],
                'attemptId' => $source['transferResult']['attemptId'],
                'state' => $source['transferResult']['state'],
                'rejectionReason' => $source['transferResult']['rejectionReason'],
            ];
        }

        if (isset($source['recoveryResult'])) {
            $result['recoveryResult'] = [
                'incarnationId' => $source['recoveryResult']['incarnationId'],
                'outcome' => $source['recoveryResult']['outcome'],
                'revision' => $source['recoveryResult']['revision'],
            ];
        }
        return $result;
    }
}
