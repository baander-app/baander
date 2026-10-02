<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Tests\Functional\TestCase;

final class NotificationPreferenceControllerTest extends TestCase
{
    private const ROUTE = '/api/notifications/preferences/';

    public function testIndexRequiresAuthentication(): void
    {
        $this->assertJsonResponse($this->anonymousRequest('GET', self::ROUTE), 401);
    }

    public function testUpdateRequiresAuthentication(): void
    {
        $this->assertJsonResponse($this->anonymousRequest('PUT', self::ROUTE, [
            'preferences' => [['category' => 'security', 'channel' => 'email', 'enabled' => false]],
        ]), 401);
    }

    public function testIndexWithoutStoredPreferencesReturnsEveryDefaultCombination(): void
    {
        $preferences = $this->readPreferences($this->createPreferenceUser());

        foreach (['security', 'background_jobs', 'media_changes', 'admin_operations'] as $category) {
            foreach (['in_app', 'email', 'push', 'webhook'] as $channel) {
                $preference = $preferences[$category . ':' . $channel];
                $enabled = $channel === 'in_app' || ($category === 'security' && $channel === 'email');
                $this->assertSame($enabled, $preference['enabled']);
                $this->assertNull($preference['updatedAt']);
            }
        }
    }

    public function testIndexMergesPartialStoredPreferencesWithDefaults(): void
    {
        $user = $this->createPreferenceUser();
        $repository = static::getContainer()->get(NotificationPreferenceRepositoryInterface::class);
        $repository->save(NotificationPreference::create(
            $user->getId(), NotificationCategory::Security, NotificationChannel::Email, enabled: false,
        ));
        $repository->save(NotificationPreference::create(
            $user->getId(), NotificationCategory::MediaChanges, NotificationChannel::Push, enabled: true,
        ));

        $preferences = $this->readPreferences($user);
        $this->assertFalse($preferences['security:email']['enabled']);
        $this->assertTrue($preferences['media_changes:push']['enabled']);
        $this->assertNotNull($preferences['security:email']['updatedAt']);
        $this->assertNotNull($preferences['media_changes:push']['updatedAt']);
        $this->assertTrue($preferences['background_jobs:in_app']['enabled']);
        $this->assertNull($preferences['background_jobs:in_app']['updatedAt']);
        $this->assertFalse($preferences['background_jobs:webhook']['enabled']);
        $this->assertNull($preferences['background_jobs:webhook']['updatedAt']);
    }

    public function testDisableAndEnableArePersistedForTheSamePreference(): void
    {
        $user = $this->createPreferenceUser();
        foreach ([false, true, false] as $enabled) {
            $this->updatePreferences($user, [
                ['category' => 'security', 'channel' => 'email', 'enabled' => $enabled],
            ]);

            $preferences = $this->readPreferences($user);
            $this->assertSame($enabled, $preferences['security:email']['enabled']);
            $this->assertNotNull($preferences['security:email']['updatedAt']);
            $connection = $this->entityManager->getConnection();
            $this->assertSame((int) $enabled, (int) $connection->fetchOne(
                'SELECT enabled::int FROM notification_preferences WHERE user_id = ? AND category = ? AND channel = ?',
                [$user->getId()->toString(), 'security', 'email'],
            ));
            $this->assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM notification_preferences WHERE user_id = ?',
                [$user->getId()->toString()],
            ));
        }
    }

    public function testBatchUpdatePersistsEverySupportedCategoryAndChannel(): void
    {
        $user = $this->createPreferenceUser();
        $items = [];
        foreach (['security', 'background_jobs', 'media_changes'] as $category) {
            foreach (['in_app', 'email', 'push', 'webhook'] as $channel) {
                $items[] = ['category' => $category, 'channel' => $channel, 'enabled' => count($items) % 2 === 0];
            }
        }
        $this->updatePreferences($user, $items);

        $preferences = $this->readPreferences($user);
        foreach ($items as $item) {
            $preference = $preferences[$item['category'] . ':' . $item['channel']];
            $this->assertSame($item['enabled'], $preference['enabled']);
            $this->assertNotNull($preference['updatedAt']);
        }
        $this->assertNull($preferences['admin_operations:in_app']['updatedAt']);
        $this->assertSame(12, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notification_preferences WHERE user_id = ?',
            [$user->getId()->toString()],
        ));
    }

    public function testUsersReadAndUpdateOnlyTheirOwnPreferences(): void
    {
        $first = $this->createPreferenceUser();
        $second = $this->createPreferenceUser();
        $this->updatePreferences($first, [
            ['category' => 'security', 'channel' => 'email', 'enabled' => false],
        ]);
        $this->updatePreferences($second, [
            ['category' => 'security', 'channel' => 'email', 'enabled' => true],
        ]);

        $this->assertFalse($this->readPreferences($first)['security:email']['enabled']);
        $this->assertTrue($this->readPreferences($second)['security:email']['enabled']);
        $this->updatePreferences($second, [
            ['category' => 'media_changes', 'channel' => 'push', 'enabled' => true],
        ]);
        $this->assertFalse($this->readPreferences($first)['media_changes:push']['enabled']);
        $this->assertTrue($this->readPreferences($second)['media_changes:push']['enabled']);
    }

    private function createPreferenceUser(): User
    {
        return $this->createTestUser('notification-preferences-' . bin2hex(random_bytes(8)) . '@baander.app');
    }

    /** @param list<array{category: string, channel: string, enabled: bool}> $preferences */
    private function updatePreferences(User $user, array $preferences): void
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', self::ROUTE, $user, ['preferences' => $preferences]),
            200,
        );
        $this->assertTrue($data['data']['updated']);
    }

    /** @return array<string, array{category: string, channel: string, enabled: bool, updatedAt: ?string}> */
    private function readPreferences(User $user): array
    {
        $this->entityManager->clear();
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', self::ROUTE, $user), 200, 'data');
        $this->assertCount(16, $data['data']);
        $preferences = [];
        foreach ($data['data'] as $preference) {
            $key = $preference['category'] . ':' . $preference['channel'];
            $this->assertArrayNotHasKey($key, $preferences);
            $this->assertIsBool($preference['enabled']);
            $this->assertArrayHasKey('updatedAt', $preference);
            $preferences[$key] = $preference;
        }

        return $preferences;
    }
}
