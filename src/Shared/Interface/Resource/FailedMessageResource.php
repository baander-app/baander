<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\FailedMessage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FailedMessageResource',
    required: ['id', 'messageClass', 'originalTransport', 'errorClass', 'errorMessage', 'failedAt', 'retryCount'],
    properties: [
        new OA\Property(property: 'id', type: 'string', pattern: '^[1-9][0-9]{0,17}$', description: 'Failure transport message id'),
        new OA\Property(property: 'messageClass', type: 'string', description: 'Message class name'),
        new OA\Property(property: 'originalTransport', type: 'string', nullable: true, description: 'Transport the message failed on'),
        new OA\Property(property: 'errorClass', type: 'string', nullable: true, description: 'Exception class of the last failure'),
        new OA\Property(property: 'errorMessage', type: 'string', nullable: true, description: 'Exception message of the last failure'),
        new OA\Property(property: 'failedAt', type: 'string', format: 'date-time', nullable: true, description: 'Time of the last failure'),
        new OA\Property(property: 'retryCount', type: 'integer', minimum: 0, description: 'Retries from the failure transport that failed again'),
    ],
)]
final class FailedMessageResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof FailedMessage);

        return [
            'id' => $source->id,
            'messageClass' => $source->messageClass,
            'originalTransport' => $source->originalTransport,
            'errorClass' => $source->errorClass,
            'errorMessage' => $source->errorMessage,
            'failedAt' => $source->failedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'retryCount' => $source->retryCount,
        ];
    }
}
