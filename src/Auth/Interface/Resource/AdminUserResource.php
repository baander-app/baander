<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\User;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminUserResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Display name', type: 'string'),
        new OA\Property(property: 'email', description: 'Email address', type: 'string', format: 'email'),
        new OA\Property(property: 'emailVerifiedAt', description: 'When the email was verified', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'roles', description: 'Assigned roles', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'disabled', description: 'Whether the account is disabled', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ],
)]
final class AdminUserResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof User);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'email' => $source->getEmail(),
            'emailVerifiedAt' => $source->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'roles' => $source->getRoles(),
            'disabled' => $source->isDisabled(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
