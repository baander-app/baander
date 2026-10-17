<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Finds the user an operator or a command names by email address or public ID, or a member names by public id. */
final readonly class UserLookup implements UserIdentifierResolverInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or public ID
     */
    public function byIdentifier(string $identifier): User
    {
        try {
            $user = str_contains($identifier, '@')
                ? $this->userRepository->findByEmail(new Email($identifier))
                : $this->userRepository->findByPublicId(PublicId::fromString($identifier));
        } catch (\InvalidArgumentException) {
            $user = null;
        }

        return $user ?? throw UserNotFoundException::forIdentifier($identifier);
    }

    /**
     * @throws UserNotFoundException when no user has the email address or public ID
     */
    public function userId(string $identifier): Uuid
    {
        return $this->byIdentifier($identifier)->getId();
    }

    /**
     * @throws UserNotFoundException when no user has the public id
     */
    public function userIdByPublicId(PublicId $publicId): Uuid
    {
        $user = $this->userRepository->findByPublicId($publicId)
            ?? throw UserNotFoundException::forIdentifier($publicId->toString());

        return $user->getId();
    }

    public function publicIdByUserId(Uuid $userId): PublicId
    {
        return ($this->userRepository->findByUuid($userId)
            ?? throw new \App\Shared\Application\Exception\NotFoundException('User not found.'))->getPublicId();
    }
}
