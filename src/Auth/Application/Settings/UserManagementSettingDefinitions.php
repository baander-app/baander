<?php

declare(strict_types=1);

namespace App\Auth\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final class UserManagementSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string CAN_VIEW_USERS = 'admin.can_view_users';
    public const string CAN_CREATE_USERS = 'admin.can_create_users';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::CAN_VIEW_USERS,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'View user list',
            description: 'Allow ADMIN role to view the user list',
            group: 'User Management',
            default: true,
            enforced: false,
        );

        yield new SettingDefinition(
            key: self::CAN_CREATE_USERS,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'Create users',
            description: 'Allow ADMIN role to create new users',
            group: 'User Management',
            default: false,
            enforced: false,
        );
    }
}
