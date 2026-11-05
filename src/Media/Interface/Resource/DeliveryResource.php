<?php

declare(strict_types=1);

namespace App\Media\Interface\Resource;

use App\Media\Application\DTO\DeliveryDecision;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['original', 'cached', 'copy', 'encoded', 'incompatible']),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'url', type: 'string', nullable: true),
    new OA\Property(property: 'requestId', description: 'The client-generated request identifier, echoed for correlation.', type: 'string', nullable: true),
    new OA\Property(property: 'jobId', type: 'string', nullable: true),
    new OA\Property(property: 'selection', nullable: true, properties: [
        new OA\Property(property: 'container', type: 'string'),
        new OA\Property(property: 'transport', type: 'string', enum: ['file', 'hls', 'dash']),
        new OA\Property(property: 'videoStreamIndex', type: 'integer', nullable: true),
        new OA\Property(property: 'audioStreamIndex', type: 'integer', nullable: true),
    ]),
])]
final class DeliveryResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        if (!$source instanceof DeliveryDecision) {
            throw new \InvalidArgumentException('Expected a media delivery decision.');
        }
        return ['kind' => $source->kind->value, 'reason' => $source->reason, 'url' => $source->url, 'requestId' => $source->requestId, 'jobId' => $source->jobId,
            'selection' => $source->selection === null ? null : [
                'container' => $source->selection->container,
                'transport' => $source->selection->transport,
                'videoStreamIndex' => $source->selection->videoStreamIndex,
                'audioStreamIndex' => $source->selection->audioStreamIndex,
            ]];
    }
}
