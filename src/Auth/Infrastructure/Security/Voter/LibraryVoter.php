<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Library resource access control.
 *
 * Works with both string subjects ('library') and actual Library entities.
 * When a Library entity is available, it checks owner_id and collaborator
 * relations. With string subjects, it falls back to role-based access.
 *
 * Attributes: VIEW, EDIT, DELETE
 */
final class LibraryVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject === 'library' || is_object($subject));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!($user instanceof SecurityUser)) {
            return false;
        }

        $roles = $token->getRoleNames();

        // Admins have unrestricted access
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return true;
        }

        // When the actual entity is available, check ownership
        if (is_object($subject) && method_exists($subject, 'getOwnerId')) {
            $userId = $user->getId();
            $isOwner = $subject->getOwnerId() === $userId;
            $hasAccess = $this->canAccessLibrary(
                \App\Shared\Domain\Model\Uuid::fromString($userId),
                \App\Shared\Domain\Model\Uuid::fromString($subject->getId()),
            );

            return match ($attribute) {
                self::VIEW, self::EDIT => $isOwner || $hasAccess,
                self::DELETE => $isOwner,
                default => false,
            };
        }

        // String subject (no entity): deny by default to prevent accidental access
        return false;
    }

    private function canAccessLibrary(\App\Shared\Domain\Model\Uuid $userId, \App\Shared\Domain\Model\Uuid $libraryId): bool
    {
        try {
            $result = $this->connection->fetchOne(
                'SELECT 1 FROM user_library_access WHERE user_id = :userId AND library_id = :libraryId LIMIT 1',
                [
                    'userId' => $userId->toString(),
                    'libraryId' => $libraryId->toString(),
                ],
            );

            return $result !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
