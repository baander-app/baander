<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'schemaVersion' => new OA\Schema(
            type: 'integer',
            enum: [1],
        ),
        'publicId' => new OA\Schema(type: 'string'),
        'revision' => new OA\Schema(type: 'integer', minimum: 0),
        'media' => new OA\Schema(ref: PlaybackMediaResource::class, nullable: true),
        'queue' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'currentTrackIndex' => new OA\Schema(type: 'integer', minimum: 0),
        'position' => new OA\Schema(type: 'number', minimum: 0),
        'playbackState' => new OA\Schema(
            type: 'string',
            enum: ['playing', 'paused', 'stopped'],
        ),
        'desiredState' => new OA\Schema(
            type: 'string',
            enum: ['playing', 'paused', 'stopped'],
        ),
        'observedState' => new OA\Schema(
            type: 'string',
            enum: ['playing', 'paused', 'stopped', 'unknown'],
        ),
        'selectedDestinationId' => new OA\Schema(type: 'string', nullable: true),
        'activeDestinationId' => new OA\Schema(type: 'string', nullable: true),
        'activeIncarnationId' => new OA\Schema(
            description: 'Incarnation identifier of the endpoint that currently executes playback.',
            type: 'string',
            nullable: true,
        ),
        'transfer' => new OA\Schema(ref: PlaybackTransferResource::class, nullable: true),
        'destinations' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(ref: PlaybackDestinationResource::class),
        ),
        'commands' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(ref: PlaybackCommandResource::class),
        ),
        'endpoint' => new OA\Schema(ref: PlaybackEndpointResource::class, nullable: true),
        'recoveryResult' => new OA\Schema(
            type: 'object',
            nullable: true,
            properties: [
                'incarnationId' => new OA\Schema(
                    description: 'The recovered endpoint incarnation, as the recovery request named it; the server issued it when that endpoint registered.',
                    type: 'string',
                ),
                'outcome' => new OA\Schema(
                    type: 'string',
                    enum: ['recovered'],
                ),
                'revision' => new OA\Schema(type: 'integer', minimum: 0),
            ],
            required: ['incarnationId', 'outcome', 'revision'],
        ),
        'transferResult' => new OA\Schema(
            type: 'object',
            nullable: true,
            properties: [
                'requestId' => new OA\Schema(
                    description: 'The client-generated request identifier, echoed for correlation.',
                    type: 'string',
                ),
                'attemptId' => new OA\Schema(description: 'Transfer attempt identifier.', type: 'string'),
                'state' => new OA\Schema(
                    type: 'string',
                    enum: ['preparing', 'quiescing', 'starting', 'committed', 'failed', 'uncertain'],
                ),
                'rejectionReason' => new OA\Schema(type: 'string', nullable: true),
            ],
            required: ['requestId', 'attemptId', 'state', 'rejectionReason'],
        ),
        'requestResult' => new OA\Schema(
            type: 'object',
            nullable: true,
            properties: [
                'requestId' => new OA\Schema(
                    description: 'The client-generated request identifier, echoed for correlation.',
                    type: 'string',
                ),
                'commandId' => new OA\Schema(description: 'Identifier of the command the request produced.', type: 'string'),
                'outcome' => new OA\Schema(type: 'string', nullable: true),
                'rejectionReason' => new OA\Schema(type: 'string', nullable: true),
            ],
            required: ['requestId', 'commandId', 'outcome', 'rejectionReason'],
        ),
    ],
    required: [
        'schemaVersion',
        'publicId',
        'revision',
        'media',
        'queue',
        'currentTrackIndex',
        'position',
        'playbackState',
        'desiredState',
        'observedState',
        'selectedDestinationId',
        'activeDestinationId',
        'activeIncarnationId',
        'transfer',
        'destinations',
        'commands',
    ],
    component: 'PlaybackSnapshotResource',
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
