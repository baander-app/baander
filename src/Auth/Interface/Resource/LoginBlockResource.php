<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\LoginBlock;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Block public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'ipAddress' => new OA\Schema(description: 'Blocked IP address', type: 'string'),
        'email' => new OA\Schema(description: 'Email used in the blocked attempt', type: 'string'),
        'fieldValue' => new OA\Schema(description: 'Form field value that triggered the block', type: 'string'),
        'userAgent' => new OA\Schema(description: 'User agent of the blocked request', type: 'string'),
        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
    ],
    required: ['publicId', 'ipAddress', 'email', 'fieldValue', 'userAgent', 'createdAt'],
    component: 'LoginBlockResource',
)]
final class LoginBlockResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof LoginBlock);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'ipAddress' => $source->getIpAddress(),
            'email' => $source->getEmail(),
            'fieldValue' => $source->getFieldValue(),
            'userAgent' => $source->getUserAgent(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
