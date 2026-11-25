<?php

declare(strict_types=1);

namespace App\UserPreference\Application;

/**
 * The bounds on what a member may store as EQ device profiles and sidebar layouts. The request
 * classes validate the sizes, the EQ profile port enforces the count, and the database checks
 * the stored sizes (chk_eq_device_profiles_*, chk_user_sidebar_configs_*).
 */
final class UserPreferenceLimits
{
    public const int EQ_PROFILES_PER_USER = 50;

    public const int EQ_DEVICE_ID_LENGTH = 255;

    /** Compact JSON bytes of an EQ profile payload. */
    public const int EQ_PAYLOAD_BYTES = 16384;

    public const int SIDEBAR_SECTIONS = 100;

    public const int SIDEBAR_ITEMS_PER_SECTION = 100;

    /** Compact JSON bytes of a submitted sidebar layout. */
    public const int SIDEBAR_BYTES = 16384;
}
