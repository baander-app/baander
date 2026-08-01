<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\User;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Internal UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Display name'),
        new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Email address'),
        new OA\Property(property: 'emailVerifiedAt', type: 'string', format: 'date-time', nullable: true, description: 'When the email was verified'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Assigned roles'),
    ],
)]
final class UserResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof User);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'email' => $source->getEmail(),
            'emailVerifiedAt' => $source->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'roles' => $source->getRoles(),
        ];
    }

    /**
     * @deprecated Use from() instead. Kept for backward compatibility during migration.
     */
    public static function fromDomain(User $user): array
    {
        return self::from($user);
    }
}
