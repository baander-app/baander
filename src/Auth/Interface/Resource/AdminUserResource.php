<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'name' => new OA\Schema(description: 'Display name', type: 'string'),
        'email' => new OA\Schema(
            description: 'Email address',
            type: 'string',
            format: 'email',
        ),
        'emailVerifiedAt' => new OA\Schema(
            description: 'When the email was verified',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'roles' => new OA\Schema(
            description: 'Assigned roles',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'disabled' => new OA\Schema(description: 'Whether the account is disabled', type: 'boolean'),
        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
        'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
    ],
    required: ['publicId', 'name', 'email', 'roles', 'disabled', 'createdAt', 'updatedAt'],
    component: 'AdminUserResource',
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
