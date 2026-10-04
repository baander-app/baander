<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Exception;

final class EqDeviceProfileNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('EQ device profile not found.');
    }
}
