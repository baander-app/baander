<?php

declare(strict_types=1);

namespace App\UserPreference\Application\DTO;

/**
 * Where a user setting's effective value comes from.
 */
enum UserSettingSource: string
{
    /** The user's explicit choice. */
    case User = 'user';
    /** The system setting the user setting follows, set by an admin or its own default. */
    case ServerDefault = 'server_default';
    /** The user setting's own default. */
    case Default = 'default';
}
