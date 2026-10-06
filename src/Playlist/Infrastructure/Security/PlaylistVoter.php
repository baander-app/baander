<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Playlist subjects allow their owner and administrators. The domain and ORM
 * model expose ownership differently; neither defines collaborator membership.
 *
 * @extends Voter<'VIEW'|'EDIT'|'DELETE'|'MANAGE_COLLABORATORS', Playlist|PlaylistEntity|'playlist'>
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
            && ($subject === 'playlist' || $subject instanceof Playlist || $subject instanceof PlaylistEntity);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        $userId = Uuid::fromString($user->getId());
        if ($subject instanceof Playlist) {
            return $subject->getUserId()->equals($userId);
        }

        if ($subject instanceof PlaylistEntity) {
            return $subject->getUser()->getId()->equals($userId);
        }

        return false;
    }
}
