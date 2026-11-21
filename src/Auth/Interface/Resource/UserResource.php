<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'The full profile of the account; GET /api/auth/me answers a delegated token with a narrower profile',
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
        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
        'roles' => new OA\Schema(
            description: 'Assigned roles',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'totpEnabled' => new OA\Schema(
            description: 'Whether TOTP is on, so a sensitive change needs a code',
            type: 'boolean',
        ),
        'pendingEmail' => new OA\Schema(
            description: 'The address an email change waits to confirm through the link sent to it',
            type: 'string',
            format: 'email',
            nullable: true,
        ),
    ],
    required: ['publicId', 'name', 'email', 'createdAt', 'roles', 'totpEnabled'],
    component: 'UserResource',
)]
final class UserResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof User);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'email' => $source->getEmail(),
            'emailVerifiedAt' => $source->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'roles' => $source->getRoles(),
            'totpEnabled' => $source->hasTotp(),
            'pendingEmail' => $source->getPendingEmail(),
        ];
    }

    /**
     * The profile a delegated token reads: the email fields only with the email scope, and
     * never the roles, which a delegated token does not carry, the account's TOTP state or an
     * unconfirmed email change.
     *
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    public static function forDelegatedToken(User $user, array $scopes): array
    {
        $profile = self::from($user);
        unset($profile['roles'], $profile['totpEnabled'], $profile['pendingEmail']);
        if (!in_array('email', $scopes, true)) {
            unset($profile['email'], $profile['emailVerifiedAt']);
        }

        return $profile;
    }

    /**
     * @deprecated Use from() instead. Kept for backward compatibility during migration.
     */
    /** @return array{publicId: string, name: string, email: string, emailVerifiedAt: string|null, createdAt: string, roles: array<array-key, string>, totpEnabled: bool, pendingEmail: string|null} */
    public static function fromDomain(User $user): array
    {
        return self::from($user);
    }
}
