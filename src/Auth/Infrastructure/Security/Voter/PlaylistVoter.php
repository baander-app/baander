<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Playlist resource access control.
 *
 * Works with both string subjects ('playlist') and actual Playlist entities.
 * When a Playlist entity is available, it checks owner_id and collaborator
 * relations. With string subjects, it falls back to role-based access.
 *
 * Attributes: VIEW, EDIT, DELETE, MANAGE_COLLABORATORS
 */
final class PlaylistVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';
    public const string MANAGE_COLLABORATORS = 'MANAGE_COLLABORATORS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::MANAGE_COLLABORATORS], true)
            && ($subject === 'playlist' || is_object($subject));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!($user instanceof SecurityUser)) {
            return false;
        }

        $roles = $token->getRoleNames();

        // Admins can do everything
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return true;
        }

        // When the actual entity is available, check ownership/collaboration
        if (is_object($subject) && method_exists($subject, 'getUserId')) {
            $userId = $user->getId();
            $isOwner = $subject->getUserId()->toString() === $userId;
            $isCollaborator = method_exists($subject, 'isCollaborator')
                && $subject->isCollaborator($userId);

            return match ($attribute) {
                self::VIEW => $isOwner || $isCollaborator,
                self::EDIT => $isOwner || $isCollaborator,
                self::DELETE => $isOwner,
                self::MANAGE_COLLABORATORS => $isOwner,
                default => false,
            };
        }

        // String subject (no entity): deny by default to prevent accidental access
        return false;
    }
}
