<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\LoginBlock;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginBlockResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Block public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'ipAddress', description: 'Blocked IP address', type: 'string'),
        new OA\Property(property: 'email', description: 'Email used in the blocked attempt', type: 'string'),
        new OA\Property(property: 'fieldValue', description: 'Form field value that triggered the block', type: 'string'),
        new OA\Property(property: 'userAgent', description: 'User agent of the blocked request', type: 'string'),
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
