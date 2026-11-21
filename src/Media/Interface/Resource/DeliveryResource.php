<?php

declare(strict_types=1);

namespace App\Media\Interface\Resource;

use App\Media\Application\DTO\DeliveryDecision;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(
            type: 'string',
            enum: ['original', 'cached', 'copy', 'encoded', 'incompatible'],
        ),
        'reason' => new OA\Schema(type: 'string', nullable: true),
        'url' => new OA\Schema(type: 'string', nullable: true),
        'requestId' => new OA\Schema(
            description: 'The client-generated request identifier, echoed for correlation.',
            type: 'string',
            nullable: true,
        ),
        'jobId' => new OA\Schema(type: 'string', nullable: true),
        'sessionId' => new OA\Schema(type: 'string', nullable: true),
        'selection' => new OA\Schema(
            nullable: true,
            properties: [
                'container' => new OA\Schema(type: 'string'),
                'transport' => new OA\Schema(
                    type: 'string',
                    enum: ['file', 'hls', 'dash'],
                ),
                'videoStreamIndex' => new OA\Schema(type: 'integer', nullable: true),
                'audioStreamIndex' => new OA\Schema(type: 'integer', nullable: true),
            ],
        ),
    ],
    required: ['kind'],
)]
final class DeliveryResource extends AbstractResource
{
    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        if (!$source instanceof DeliveryDecision) {
            throw new \InvalidArgumentException('Expected a media delivery decision.');
        }
        return [
            'kind' => $source->kind->value,
            'reason' => $source->reason,
            'url' => $source->url,
            'requestId' => $source->requestId,
            'jobId' => $source->jobId,
            'sessionId' => $source->sessionId,
            'selection' => $source->selection === null ? null : [
                'container' => $source->selection->container,
                'transport' => $source->selection->transport,
                'videoStreamIndex' => $source->selection->videoStreamIndex,
                'audioStreamIndex' => $source->selection->audioStreamIndex,
            ],
        ];
    }
}
