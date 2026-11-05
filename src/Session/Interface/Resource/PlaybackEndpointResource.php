<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackEndpointResource',
    required: ['destinationId', 'incarnationId', 'epoch'],
    properties: [
        new OA\Property(property: 'destinationId', type: 'string'),
        new OA\Property(property: 'incarnationId', description: 'Client-generated endpoint incarnation identifier.', type: 'string'),
        new OA\Property(property: 'epoch', type: 'integer', minimum: 1),
        new OA\Property(property: 'recoveryCredential', type: 'string', nullable: true, description: 'Private stop-only recovery credential for durable local storage.'),
        new OA\Property(property: 'recoveryExpiresAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'recoveryTabId', description: 'Client-generated tab identifier that names the recovery record.', type: 'string', nullable: true),
        new OA\Property(property: 'capability', type: 'string', nullable: true, description: 'Returned only to the HTTP registration caller; keep in tab memory.'),
    ],
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
