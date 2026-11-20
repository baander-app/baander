<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\PublicDiagnosticText;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FailedMessageResource',
    required: ['id', 'messageClass', 'originalTransport', 'errorClass', 'errorMessage', 'failedAt', 'retryCount'],
    properties: [
        new OA\Property(property: 'id', description: 'Failure transport message id', type: 'string', pattern: '^[1-9][0-9]{0,17}$'),
        new OA\Property(property: 'messageClass', description: 'Message class name', type: 'string'),
        new OA\Property(property: 'originalTransport', description: 'Transport the message failed on', type: 'string', nullable: true),
        new OA\Property(property: 'errorClass', description: 'Exception class of the last failure', type: 'string', nullable: true),
        new OA\Property(property: 'errorMessage', description: 'Exception message of the last failure', type: 'string', nullable: true),
        new OA\Property(property: 'failedAt', description: 'Time of the last failure', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'retryCount', description: 'Retries from the failure transport that failed again', type: 'integer', minimum: 0),
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
            'errorMessage' => $source->errorMessage === null ? null : PublicDiagnosticText::redact($source->errorMessage),
            'failedAt' => $source->failedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'retryCount' => $source->retryCount,
        ];
    }
}
