<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides every playlist route on the aggregate, which carries its collaborators.
 *
 * The owner may do everything but leave. A collaborator may view, may change the
 * songs while the playlist is collaborative, and may leave (remove their own
 * collaborator row); the controller asks LEAVE only when the row named in the
 * request is the actor's own. An administrator may view any playlist and nothing
 * more: a playlist is personal data. Everyone else is refused.
 *
 * @extends Voter<'VIEW'|'EDIT_SONGS'|'EDIT'|'DELETE'|'MANAGE_COLLABORATORS'|'LEAVE', Playlist>
 */
final class PlaylistVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT_SONGS = 'EDIT_SONGS';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';
    public const string MANAGE_COLLABORATORS = 'MANAGE_COLLABORATORS';
    public const string LEAVE = 'LEAVE';

    private const array ATTRIBUTES = [
        self::VIEW,
        self::EDIT_SONGS,
        self::EDIT,
        self::DELETE,
        self::MANAGE_COLLABORATORS,
        self::LEAVE,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Playlist;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return false;
        }

        $userId = Uuid::fromString($user->getId());
        $isOwner = $subject->getUserId()->equals($userId);
        $isCollaborator = $subject->isCollaborator($userId);
        $isAdmin = in_array('ROLE_ADMIN', $token->getRoleNames(), true);

        return match ($attribute) {
            self::VIEW => $isOwner || $isCollaborator || $isAdmin,
            self::EDIT_SONGS => $isOwner || ($isCollaborator && $subject->isCollaborative()),
            self::EDIT, self::DELETE, self::MANAGE_COLLABORATORS => $isOwner,
            self::LEAVE => $isCollaborator,
        };
    }
}
