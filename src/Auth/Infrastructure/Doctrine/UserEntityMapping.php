<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;

/**
 * Rebuilds the User aggregate from its row, for every repository that loads users, directly
 * or through a token or code. One mapping, so no reader drops a field.
 */
final class UserEntityMapping
{
    public static function toDomain(UserEntity $entity): User
    {
        return User::reconstitute(new UserState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            email: $entity->getEmail(),
            password: $entity->getPassword(),
            totpSecretCiphertext: $entity->getTotpSecretCiphertext(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            emailVerifiedAt: $entity->getEmailVerifiedAt(),
            roles: $entity->getRoles(),
            disabled: $entity->isDisabled(),
            pendingEmail: $entity->getPendingEmail(),
            credentialEpoch: $entity->getCredentialEpoch(),
        ));
    }
}
