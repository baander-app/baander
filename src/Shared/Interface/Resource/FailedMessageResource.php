<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\PublicDiagnosticText;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(
            description: 'Failure transport message id',
            type: 'string',
            pattern: '^[1-9][0-9]{0,17}$',
        ),
        'messageClass' => new OA\Schema(description: 'Message class name', type: 'string'),
        'originalTransport' => new OA\Schema(
            description: 'Transport the message failed on',
            type: 'string',
            nullable: true,
        ),
        'errorClass' => new OA\Schema(
            description: 'Exception class of the last failure',
            type: 'string',
            nullable: true,
        ),
        'errorMessage' => new OA\Schema(
            description: 'Exception message of the last failure',
            type: 'string',
            nullable: true,
        ),
        'failedAt' => new OA\Schema(
            description: 'Time of the last failure',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'retryCount' => new OA\Schema(
            description: 'Retries from the failure transport that failed again',
            type: 'integer',
            minimum: 0,
        ),
    ],
    required: ['id', 'messageClass', 'originalTransport', 'errorClass', 'errorMessage', 'failedAt', 'retryCount'],
    component: 'FailedMessageResource',
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
            'errorMessage' => $source->errorMessage === null ? null : PublicDiagnosticText::redact($source->errorMessage),
            'failedAt' => $source->failedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'retryCount' => $source->retryCount,
        ];
    }
}
