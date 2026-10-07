<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Voter;

use App\Auth\Application\Settings\UserManagementSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides what an admin may do in user management over HTTP.
 *
 * Super admins may always list and create users. Other admins may list users
 * while `admin.can_view_users` is on, and create users while
 * `admin.can_create_users` is on, but only with `ROLE_USER`: the toggle never
 * lets an admin create another admin. Both settings are read on every vote.
 * The console commands are not gated, because CLI access has full authority.
 *
 * @extends Voter<string, mixed>
 */
final class UserManagementVoter extends Voter
{
    public const string LIST_USERS = 'USER_MANAGEMENT_LIST';

    /** The subject is the list of roles requested for the new user. */
    public const string CREATE_USER = 'USER_MANAGEMENT_CREATE';

    private const array ROLES_AN_ADMIN_MAY_GRANT = ['ROLE_USER'];

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly SystemSettingsPortInterface $systemSettings,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::LIST_USERS || $attribute === self::CREATE_USER;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->accessDecisionManager->decide($token, ['ROLE_SUPER_ADMIN'])) {
            return true;
        }

        if (!$this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return false;
        }

        return match ($attribute) {
            self::LIST_USERS => $this->systemSettings->get(UserManagementSettingDefinitions::CAN_VIEW_USERS) === true,
            self::CREATE_USER => $this->systemSettings->get(UserManagementSettingDefinitions::CAN_CREATE_USERS) === true
                && $this->grantsOnlyOrdinaryRoles($subject),
            default => false,
        };
    }

    private function grantsOnlyOrdinaryRoles(mixed $requestedRoles): bool
    {
        if (!is_array($requestedRoles)) {
            return false;
        }

        foreach ($requestedRoles as $role) {
            if (!in_array($role, self::ROLES_AN_ADMIN_MAY_GRANT, true)) {
                return false;
            }
        }

        return true;
    }
}
