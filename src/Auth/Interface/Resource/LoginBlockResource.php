<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\LoginBlock;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginBlockResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Block public ID'),
        new OA\Property(property: 'ipAddress', type: 'string', description: 'Blocked IP address'),
        new OA\Property(property: 'email', type: 'string', description: 'Email used in the blocked attempt'),
        new OA\Property(property: 'fieldValue', type: 'string', description: 'Form field value that triggered the block'),
        new OA\Property(property: 'userAgent', type: 'string', description: 'User agent of the blocked request'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
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
