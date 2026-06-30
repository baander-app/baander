<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for administrative access control.
 *
 * Single attribute: ADMIN_ACCESS — grants access only to users
 * with the ROLE_ADMIN role. All other users are denied.
 */
final class AdminVoter extends Voter
{
    public const string ADMIN_ACCESS = 'ADMIN_ACCESS';
    public const string USER_MANAGEMENT = 'USER_MANAGEMENT';
    public const string SYSTEM_SETTINGS = 'SYSTEM_SETTINGS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::ADMIN_ACCESS,
            self::USER_MANAGEMENT,
            self::SYSTEM_SETTINGS,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return match ($attribute) {
            self::ADMIN_ACCESS => $this->isAdmin($token),
            self::USER_MANAGEMENT, self::SYSTEM_SETTINGS => $this->isSuperAdmin($token),
            default => false,
        };
    }

    private function isAdmin(TokenInterface $token): bool
    {
        $roles = $token->getRoleNames();

        return in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);
    }

    private function isSuperAdmin(TokenInterface $token): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $token->getRoleNames(), true);
    }
}
