<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Functional tests for notification management.
 *
 * Covers NotificationController:
 *   GET    /api/notifications/              index (filters: category, unread, since)
 *   GET    /api/notifications/unread-count   unread count
 *   PATCH  /api/notifications/{id}/read      mark one as read
 *   PATCH  /api/notifications/read-all       mark all as read
 *   DELETE /api/notifications/{id}           delete
 *
 * Single-notification writes require ownership, including for unrelated admins.
 */
final class NotificationControllerTest extends TestCase
{
    private NotificationRepositoryInterface $notificationRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationRepository = static::getContainer()->get(NotificationRepositoryInterface::class);
    }

    // ---------------------------------------------------------------
    // GET / (index)
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/notifications/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsEmptyForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/', $user),
            200,
            'data',
        );

        $this->assertSame([], $data['data']);
    }

    public function testIndexReturnsOnlyOwnedNotifications(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();

        $owned = $this->createNotification($user, eventType: 'owned.event');
        $this->createNotification($other, eventType: 'other.event');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/', $user),
            200,
            'data',
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame($owned->getPublicId()->toString(), $data['data'][0]['publicId']);
    }

    public function testIndexFiltersByCategory(): void
    {
        $user = $this->createTestUser();
        $this->createNotification($user, category: NotificationCategory::Security);
        $this->createNotification($user, category: NotificationCategory::MediaChanges);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/?category=security', $user),
            200,
            'data',
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame('security', $data['data'][0]['category']);
    }

    public function testIndexWithInvalidCategoryReturns400(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/notifications/?category=bogus', $user);

        $this->assertJsonResponse($response, 400);
    }

    public function testIndexWithInvalidSinceReturns400(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/notifications/?since=not-a-date', $user);

        $this->assertJsonResponse($response, 400);
    }

    public function testIndexUnreadFilterExcludesReadNotifications(): void
    {
        $user = $this->createTestUser();
        $read = $this->createNotification($user);
        $this->notificationRepository->markAsRead($read->getId());

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/?unread=true', $user),
            200,
            'data',
        );

        $this->assertCount(0, $data['data'], 'Read notifications must be excluded by the unread filter.');
    }

    // ---------------------------------------------------------------
    // GET /unread-count
    // ---------------------------------------------------------------

    public function testUnreadCountRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/notifications/unread-count');

        $this->assertJsonResponse($response, 401);
    }

    public function testUnreadCountReflectsReadState(): void
    {
        $user = $this->createTestUser();
        $this->createNotification($user);
        $read = $this->createNotification($user);
        $this->notificationRepository->markAsRead($read->getId());

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/unread-count', $user),
            200,
            'data',
        );

        $this->assertSame(1, $data['data']['count']);
    }

    // ---------------------------------------------------------------
    // PATCH /{publicId}/read
    // ---------------------------------------------------------------

    public function testMarkReadRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PATCH', '/api/notifications/some-id/read');

        $this->assertJsonResponse($response, 401);
    }

    public function testMarkReadReturns404ForUnknownId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PATCH', '/api/notifications/00000000-0000-0000-0000-000000000000/read', $user);

        $this->assertJsonResponse($response, 404);
    }

    public function testMarkReadMarksAsRead(): void
    {
        $user = $this->createTestUser();
        $notification = $this->createNotification($user);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/notifications/' . $notification->getPublicId()->toString() . '/read', $user),
            200,
            'data',
        );

        $this->assertTrue($data['data']['isRead']);
    }

    #[DataProvider('unrelatedReaders')]
    public function testMarkReadRejectsUnrelatedUsers(bool $admin, bool $alreadyRead): void
    {
        $owner = $this->createNotificationUser();
        $intruder = $this->createNotificationUser($admin);
        $notification = $this->createNotification($owner);
        if ($alreadyRead) {
            $this->notificationRepository->markAsRead($notification->getId());
        }
        $before = $this->storedNotification($notification);

        $response = $this->authenticatedRequest(
            'PATCH',
            '/api/notifications/' . $notification->getPublicId()->toString() . '/read',
            $intruder,
        );

        $data = $this->assertJsonResponse($response, 403);
        $this->assertPrivateNotificationIsAbsent($data, $notification);
        $this->assertSame($before, $this->storedNotification($notification));
    }

    public function testOwnerCanMarkReadRepeatedly(): void
    {
        $owner = $this->createNotificationUser();
        $notification = $this->createNotification($owner);
        $uri = '/api/notifications/' . $notification->getPublicId()->toString() . '/read';

        $first = $this->assertJsonResponse($this->authenticatedRequest('PATCH', $uri, $owner), 200, 'data');
        $this->assertTrue($first['data']['isRead']);
        $stored = $this->storedNotification($notification);
        $this->entityManager->clear();
        $second = $this->assertJsonResponse($this->authenticatedRequest('PATCH', $uri, $owner), 200, 'data');
        $this->assertSame($first['data'], $second['data']);
        $this->assertSame($stored, $this->storedNotification($notification));
    }

    // ---------------------------------------------------------------
    // PATCH /read-all
    // ---------------------------------------------------------------

    public function testMarkAllReadRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PATCH', '/api/notifications/read-all');

        $this->assertJsonResponse($response, 401);
    }

    public function testMarkAllReadClearsUnreadCount(): void
    {
        $user = $this->createTestUser();
        $this->createNotification($user);
        $this->createNotification($user);

        $this->authenticatedRequest('PATCH', '/api/notifications/read-all', $user);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/unread-count', $user),
            200,
            'data',
        );

        $this->assertSame(0, $data['data']['count']);
    }

    // ---------------------------------------------------------------
    // DELETE /{publicId}
    // ---------------------------------------------------------------

    public function testDeleteRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/notifications/some-id');

        $this->assertJsonResponse($response, 401);
    }

    public function testDeleteReturns404ForUnknownId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/notifications/00000000-0000-0000-0000-000000000000', $user);

        $this->assertJsonResponse($response, 404);
    }

    public function testDeleteRemovesNotification(): void
    {
        $user = $this->createTestUser();
        $notification = $this->createNotification($user);
        $publicId = $notification->getPublicId()->toString();

        $response = $this->authenticatedRequest('DELETE', '/api/notifications/' . $publicId, $user);

        $this->assertSame(204, $response->getStatusCode());

        // Gone from the index.
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/', $user),
            200,
            'data',
        );
        $this->assertSame([], $data['data']);
    }

    #[DataProvider('unrelatedUsers')]
    public function testDeleteRejectsUnrelatedUsers(bool $admin): void
    {
        $owner = $this->createNotificationUser();
        $intruder = $this->createNotificationUser($admin);
        $notification = $this->createNotification($owner);
        $before = $this->storedNotification($notification);

        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/notifications/' . $notification->getPublicId()->toString(),
            $intruder,
        );

        $data = $this->assertJsonResponse($response, 403);
        $this->assertPrivateNotificationIsAbsent($data, $notification);
        $this->assertSame($before, $this->storedNotification($notification));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return iterable<string, array{bool}> */
    public static function unrelatedUsers(): iterable
    {
        yield 'ordinary user' => [false];
        yield 'unrelated admin' => [true];
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function unrelatedReaders(): iterable
    {
        yield 'ordinary user, unread' => [false, false];
        yield 'unrelated admin, unread' => [true, false];
        yield 'ordinary user, already read' => [false, true];
        yield 'unrelated admin, already read' => [true, true];
    }

    private function createNotificationUser(bool $admin = false): User
    {
        $email = 'notification-owner-' . bin2hex(random_bytes(8)) . '@baander.app';
        if (!$admin) {
            return $this->createTestUser($email);
        }

        $user = User::createByOperator(new Email($email), password_hash('password123', PASSWORD_BCRYPT), 'Unrelated Admin', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->userRepository->save($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function storedNotification(Notification $notification): array
    {
        $stored = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM notifications WHERE id = ?',
            [$notification->getId()->toString()],
        );
        $this->assertNotFalse($stored, 'A denied write must preserve the notification row.');

        return $stored;
    }

    /** @param array<string, mixed> $response */
    private function assertPrivateNotificationIsAbsent(array $response, Notification $notification): void
    {
        $this->assertArrayHasKey('error', $response);
        $this->assertArrayNotHasKey('data', $response);
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        foreach ([$notification->getTitle(), $notification->getBody(), $notification->getUserId()->toString()] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
        foreach (['title', 'body', 'parameters', 'referenceData'] as $privateField) {
            $this->assertStringNotContainsString('"' . $privateField . '"', $json);
        }
    }

    private function createNotification(
        User $user,
        NotificationCategory $category = NotificationCategory::Security,
        string $eventType = 'test.event',
    ): Notification {
        $notification = Notification::create(
            $user->getId(),
            $category,
            $eventType,
            'Test Title',
            'Test Body',
        );
        $this->notificationRepository->save($notification);

        return $notification;
    }
}
