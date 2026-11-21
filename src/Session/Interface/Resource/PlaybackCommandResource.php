<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(description: 'Command identifier.', type: 'string'),
        'attemptId' => new OA\Schema(description: 'Transfer attempt identifier.', type: 'string'),
        'revision' => new OA\Schema(type: 'integer', minimum: 0),
        'incarnationId' => new OA\Schema(
            description: 'Incarnation identifier of the endpoint the command is for, issued by the server when that endpoint registered.',
            type: 'string',
        ),
        'epoch' => new OA\Schema(type: 'integer', minimum: 1),
        'type' => new OA\Schema(
            type: 'string',
            enum: ['prepare', 'quiesce', 'start', 'stop', 'play', 'pause', 'seek', 'load', 'observe', 'volume', 'queue'],
        ),
        'payload' => new OA\Schema(
            type: 'object',
            properties: [
                'media' => new OA\Schema(ref: PlaybackMediaResource::class, nullable: true),
                'queue' => new OA\Schema(
                    type: 'array',
                    items: new OA\Schema(type: 'string'),
                ),
                'currentTrackIndex' => new OA\Schema(type: 'integer', minimum: 0),
                'position' => new OA\Schema(type: 'number', minimum: 0),
                'desiredState' => new OA\Schema(
                    type: 'string',
                    enum: ['playing', 'paused', 'stopped'],
                ),
                'volume' => new OA\Schema(
                    type: 'number',
                    nullable: true,
                    minimum: 0,
                    maximum: 1,
                ),
            ],
            required: ['media', 'queue', 'currentTrackIndex', 'position', 'desiredState'],
        ),
        'expiresAt' => new OA\Schema(type: 'string', format: 'date-time'),
    ],
    required: ['id', 'attemptId', 'revision', 'incarnationId', 'epoch', 'type', 'payload', 'expiresAt'],
    component: 'PlaybackCommandResource',
)]
final class PlaybackCommandResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        $payload = $source['payload'];

        return [
            'id' => $source['id'],
            'attemptId' => $source['attemptId'],
            'revision' => $source['revision'],
            'incarnationId' => $source['incarnationId'],
            'epoch' => $source['epoch'],
            'type' => $source['type'],
            'payload' => [
                'media' => $payload['media'] === null ? null : PlaybackMediaResource::from($payload['media']),
                'queue' => $payload['queue'],
                'currentTrackIndex' => $payload['currentTrackIndex'],
                'position' => $payload['position'],
                'desiredState' => $payload['desiredState'],
                ...(isset($payload['volume']) ? ['volume' => $payload['volume']] : []),
            ],
            'expiresAt' => $source['expiresAt'],
        ];
    }
}
