<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

/** The changes that take super admin status away, which SuperAdminRemovalRace runs through their production handlers. */
enum SuperAdminRemoval: string
{
    case Demotion = 'demotion';
    case Disable = 'disable';
    case Deletion = 'deletion';
}
