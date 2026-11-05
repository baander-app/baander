<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackCommandResource',
    required: ['id', 'attemptId', 'revision', 'incarnationId', 'epoch', 'type', 'payload', 'expiresAt'],
    properties: [
        new OA\Property(property: 'id', description: 'Command identifier.', type: 'string'),
        new OA\Property(property: 'attemptId', description: 'Transfer attempt identifier.', type: 'string'),
        new OA\Property(property: 'revision', type: 'integer', minimum: 0),
        new OA\Property(property: 'incarnationId', description: 'Client-generated endpoint incarnation identifier.', type: 'string'),
        new OA\Property(property: 'epoch', type: 'integer', minimum: 1),
        new OA\Property(property: 'type', type: 'string', enum: ['prepare', 'quiesce', 'start', 'stop', 'play', 'pause', 'seek', 'load', 'observe', 'volume', 'queue']),
        new OA\Property(property: 'payload', required: ['media', 'queue', 'currentTrackIndex', 'position', 'desiredState'], properties: [
            new OA\Property(property: 'media', ref: new Model(type: PlaybackMediaResource::class), nullable: true),
            new OA\Property(property: 'queue', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'currentTrackIndex', type: 'integer', minimum: 0),
            new OA\Property(property: 'position', type: 'number', minimum: 0),
            new OA\Property(property: 'desiredState', type: 'string', enum: ['playing', 'paused', 'stopped']),
            new OA\Property(property: 'volume', type: 'number', minimum: 0, maximum: 1, nullable: true),
        ], type: 'object'),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
    ],
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
