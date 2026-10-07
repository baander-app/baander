<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class UserPreferenceSettingsContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testAuthAndNotificationMayUseTheSettingsContractButNotOtherUserPreferenceClasses(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\UserPreference\Application\Port;
interface UserSettingsContractInterface {
    public function setting(string $userId, string $key): UserSettingView;
}
final class UserSettingView {}
namespace App\UserPreference\Application\Service;
final class InternalReader {}
namespace App\Auth\Application\Service;
final class BoundaryAdminSettings {
    public function read(\App\UserPreference\Application\Port\UserSettingsContractInterface $settings): \App\UserPreference\Application\Port\UserSettingView {}
    public function internal(\App\UserPreference\Application\Service\InternalReader $reader): void {}
}
namespace App\Notification\Application\Handler;
final class BoundaryEmailHandler {
    public function __construct(\App\UserPreference\Application\Port\UserSettingsContractInterface $settings) {}
}
SOURCE);

        self::assertSame(
            ['App\Auth\Application\Service\BoundaryAdminSettings must not depend on App\UserPreference\Application\Service\InternalReader'],
            $violations,
        );
    }
}
