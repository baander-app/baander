<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Song resource access control.
 *
 * Works with Song entities that expose an owner identifier via getOwnerId().
 * Real Song aggregates without ownership metadata are not supported and
 * cause the voter to abstain. String subjects ('song') are supported but
 * denied by default to prevent accidental access.
 *
 * Attributes: VIEW, EDIT, DELETE
 */
final class SongVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject === 'song' || (is_object($subject) && method_exists($subject, 'getOwnerId')));
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

            return match ($attribute) {
                self::VIEW, self::EDIT, self::DELETE => $isOwner,
                default => false,
            };
        }

        // String subject (no entity): deny by default to prevent accidental access
        return false;
    }
}
