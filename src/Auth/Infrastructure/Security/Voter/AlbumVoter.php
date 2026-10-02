<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Album subjects retain administrator access. The current domain and ORM
 * models have no owner field, so ordinary access stays denied.
 *
 * @extends Voter<'VIEW'|'EDIT'|'DELETE', Album|AlbumEntity|'album'>
 */
final class AlbumVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject === 'album' || $subject instanceof Album || $subject instanceof AlbumEntity);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof SecurityUser) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        return false;
    }
}
