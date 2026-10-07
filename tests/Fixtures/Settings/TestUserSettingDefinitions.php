<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

/**
 * User settings that exist only in the test environment, so the user
 * settings API can be tested independently of the settings real contexts own.
 */
final class TestUserSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string FOLLOWS_SERVER = 'test.follows_server_language';
    public const string ADMIN_ONLY = 'test.admin_only';
    public const string OWN_DEFAULT = 'test.own_default';

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::FOLLOWS_SERVER,
            type: SettingValueType::Enum,
            scope: SettingScope::User,
            label: 'Test language',
            description: 'Follows the server default language.',
            group: 'Test',
            allowedValues: ['en', 'da', 'th'],
            editRole: SettingDefinition::ROLE_USER,
            fallbackKey: 'i18n.default_language',
        );

        yield new SettingDefinition(
            key: self::ADMIN_ONLY,
            type: SettingValueType::Boolean,
            scope: SettingScope::User,
            label: 'Test admin-only flag',
            description: 'Only a super admin may change it.',
            group: 'Test',
            default: false,
        );

        yield new SettingDefinition(
            key: self::OWN_DEFAULT,
            type: SettingValueType::Integer,
            scope: SettingScope::User,
            label: 'Test count',
            description: 'Has its own default.',
            group: 'Test',
            default: 3,
            min: 1,
            max: 10,
            editRole: SettingDefinition::ROLE_USER,
        );
    }
}
