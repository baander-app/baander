<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Port\SelfRegistrationInterface;
use App\Auth\Application\Settings\UserManagementSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;

/** Self-registration follows the `auth.registration_open` server setting, which ships closed. */
final readonly class SelfRegistration implements SelfRegistrationInterface
{
    public function __construct(
        private SystemSettingsPortInterface $systemSettings,
    ) {
    }

    #[\Override]
    public function isOpen(): bool
    {
        return $this->systemSettings->get(UserManagementSettingDefinitions::REGISTRATION_OPEN) === true;
    }
}
