<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Library\Domain\Model\Library;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Library subjects retain administrator access. Ordinary access stays denied;
 * library membership policy is enforced separately by the application port.
 *
 * @extends Voter<'VIEW'|'EDIT'|'DELETE', Library|LibraryEntity|'library'>
 */
final class LibraryVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject === 'library' || $subject instanceof Library || $subject instanceof LibraryEntity);
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

        return false;
    }
}
