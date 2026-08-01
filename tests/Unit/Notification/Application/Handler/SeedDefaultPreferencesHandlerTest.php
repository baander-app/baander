<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Notification\Application\Handler\SeedDefaultPreferencesHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class SeedDefaultPreferencesHandlerTest extends TestCase
{
    public function testSeedsDefaultPreferencesForNewUser(): void
    {
        $preferenceRepository = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $userId = Uuid::generate();

        // 3 categories × 4 channels = 12 preferences
        $preferenceRepository->expects($this->exactly(12))->method('save');

        $handler = new SeedDefaultPreferencesHandler($preferenceRepository);
        $handler(new SeedDefaultPreferencesCommand($userId));
    }

    public function testSeedsCorrectDefaults(): void
    {
        $savedPreferences = [];
        $preferenceRepository = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $preferenceRepository->method('save')
            ->willReturnCallback(function (\App\Notification\Domain\Model\NotificationPreference $pref) use (&$savedPreferences): void {
                $savedPreferences[] = [
                    'category' => $pref->getCategory()->value,
                    'channel' => $pref->getChannel()->value,
                    'enabled' => $pref->isEnabled(),
                ];
            });

        $handler = new SeedDefaultPreferencesHandler($preferenceRepository);
        $handler(new SeedDefaultPreferencesCommand(Uuid::generate()));

        // Check that InApp is enabled for all categories
        $inAppPrefs = array_filter($savedPreferences, fn (array $p) => $p['channel'] === 'in_app');
        foreach ($inAppPrefs as $pref) {
            $this->assertTrue($pref['enabled']);
        }

        // Check that Security+Email is enabled
        $securityEmail = array_filter(
            $savedPreferences,
            fn (array $p) => $p['category'] === 'security' && $p['channel'] === 'email',
        );
        $this->assertCount(1, $securityEmail);
        $this->assertTrue(reset($securityEmail)['enabled']);

        // Check that Push and Webhook are disabled for all categories
        $pushPrefs = array_filter($savedPreferences, fn (array $p) => $p['channel'] === 'push');
        foreach ($pushPrefs as $pref) {
            $this->assertFalse($pref['enabled']);
        }

        $webhookPrefs = array_filter($savedPreferences, fn (array $p) => $p['channel'] === 'webhook');
        foreach ($webhookPrefs as $pref) {
            $this->assertFalse($pref['enabled']);
        }
    }
}
