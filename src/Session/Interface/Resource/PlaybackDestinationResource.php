<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackDestinationResource',
    required: ['destinationId', 'name', 'kind', 'capabilities', 'incarnationId', 'epoch', 'observedState', 'position', 'observedVolume', 'available'],
    properties: [
        new OA\Property(property: 'destinationId', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['browser']),
        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string', enum: ['song', 'video', 'volume'])),
        new OA\Property(property: 'incarnationId', description: 'Endpoint incarnation identifier, issued by the server when the endpoint registered.', type: 'string', nullable: true),
        new OA\Property(property: 'epoch', type: 'integer', nullable: true, minimum: 1),
        new OA\Property(property: 'observedState', type: 'string', nullable: true, enum: ['playing', 'paused', 'stopped', 'unknown']),
        new OA\Property(property: 'position', type: 'number', nullable: true, minimum: 0),
        new OA\Property(property: 'observedVolume', type: 'number', nullable: true, maximum: 1, minimum: 0),
        new OA\Property(property: 'available', type: 'boolean'),
    ],
)]
final class PlaybackDestinationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'destinationId' => $source['destinationId'],
            'name' => $source['name'],
            'kind' => $source['kind'],
            'capabilities' => $source['capabilities'],
            'incarnationId' => $source['incarnationId'],
            'epoch' => $source['epoch'],
            'observedState' => $source['observedState'],
            'position' => $source['position'],
            'observedVolume' => $source['observedVolume'],
            'available' => $source['available'],
        ];
    }
}
