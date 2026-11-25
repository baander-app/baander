<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use App\UserPreference\Application\UserPreferenceLimits;

final class EqDeviceProfileLimitReached extends ConflictException
{
    public function __construct()
    {
        parent::__construct(
            sprintf('A user may keep at most %d EQ device profiles.', UserPreferenceLimits::EQ_PROFILES_PER_USER),
            ['reason' => 'eq_profile_limit', 'limit' => UserPreferenceLimits::EQ_PROFILES_PER_USER],
        );
    }
}
