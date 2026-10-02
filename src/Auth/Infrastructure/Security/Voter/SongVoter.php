<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only the explicit song string is supported. The current Song domain and ORM
 * models have no owner field and continue to make this voter abstain.
 *
 * @extends Voter<'VIEW'|'EDIT'|'DELETE', 'song'>
 */
final class SongVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject === 'song');
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
