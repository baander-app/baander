<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\User;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'name', type: 'string', description: 'Display name'),
        new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Email address; absent for a delegated token without the email scope'),
        new OA\Property(property: 'emailVerifiedAt', type: 'string', format: 'date-time', nullable: true, description: 'When the email was verified; absent for a delegated token without the email scope'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Assigned roles; absent for a delegated token'),
    ],
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
        ];
    }

    /**
     * The profile a delegated token reads: the email fields only with the email scope, and
     * never the roles, which a delegated token does not carry.
     *
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    public static function forDelegatedToken(User $user, array $scopes): array
    {
        $profile = self::from($user);
        unset($profile['roles']);
        if (!in_array('email', $scopes, true)) {
            unset($profile['email'], $profile['emailVerifiedAt']);
        }

        return $profile;
    }

    /**
     * @deprecated Use from() instead. Kept for backward compatibility during migration.
     */
    /** @return array{publicId: string, name: string, email: string, emailVerifiedAt: string|null, createdAt: string, roles: array<array-key, string>} */
    public static function fromDomain(User $user): array
    {
        return self::from($user);
    }
}
