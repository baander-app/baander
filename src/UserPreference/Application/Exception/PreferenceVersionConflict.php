<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Exception;

final class PreferenceVersionConflict extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Preference version conflict.');
    }
}
