<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'destinationId' => new OA\Schema(type: 'string'),
        'incarnationId' => new OA\Schema(
            description: 'Endpoint incarnation identifier, issued by the server when the endpoint registers.',
            type: 'string',
        ),
        'epoch' => new OA\Schema(type: 'integer', minimum: 1),
        'recoveryCredential' => new OA\Schema(
            description: 'Private stop-only recovery credential for durable local storage.',
            type: 'string',
            nullable: true,
        ),
        'recoveryExpiresAt' => new OA\Schema(
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'recoveryTabId' => new OA\Schema(
            description: 'Client-generated tab identifier that names the recovery record.',
            type: 'string',
            nullable: true,
        ),
        'capability' => new OA\Schema(
            description: 'Returned only to the HTTP registration caller; keep in tab memory.',
            type: 'string',
            nullable: true,
        ),
    ],
    required: ['destinationId', 'incarnationId', 'epoch'],
    component: 'PlaybackEndpointResource',
)]
final class PlaybackEndpointResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        $result = [
            'destinationId' => $source['destinationId'],
            'incarnationId' => $source['incarnationId'],
            'epoch' => $source['epoch'],
        ];
        if (isset($source['capability'])) {
            $result['capability'] = $source['capability'];
        }

        foreach (['recoveryCredential', 'recoveryExpiresAt', 'recoveryTabId'] as $field) {
            if (isset($source[$field])) {
                $result[$field] = $source[$field];
            }
        }
        return $result;
    }
}
