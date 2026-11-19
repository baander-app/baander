<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Role decisions through the application's role hierarchy, as voters see them at
 * runtime: a super admin is an admin and a user.
 */
final class RoleHierarchyDecisions
{
    public static function manager(): AccessDecisionManagerInterface
    {
        return new AccessDecisionManager([
            new RoleHierarchyVoter(new RoleHierarchy([
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
                'ROLE_ADMIN' => ['ROLE_USER'],
            ])),
        ]);
    }
}
