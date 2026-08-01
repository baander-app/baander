<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Tests\Functional\TestCase;

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
 * Notable behaviour pinned here: markRead() and delete() look up a notification
 * by public ID WITHOUT verifying ownership — any authenticated user can mark-read
 * or delete another user's notification. See testMarkReadDoesNotEnforceOwnership
 * and testDeleteDoesNotEnforceOwnership.
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

    public function testMarkReadDoesNotEnforceOwnership(): void
    {
        // BUG PIN: findByPublicId() ignores userId, so any authenticated user can
        // mark another user's notification as read. The controller never checks
        // ownership, returning 200 instead of 403.
        $owner = $this->createTestUser();
        $intruder = $this->createTestUser();
        $notification = $this->createNotification($owner);

        $response = $this->authenticatedRequest(
            'PATCH',
            '/api/notifications/' . $notification->getPublicId()->toString() . '/read',
            $intruder,
        );

        $this->assertSame(200, $response->getStatusCode(), 'markRead has no ownership check (bug).');
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

    public function testDeleteDoesNotEnforceOwnership(): void
    {
        // BUG PIN: delete() looks up by public ID only — no ownership check.
        // Any authenticated user can delete another user's notification.
        $owner = $this->createTestUser();
        $intruder = $this->createTestUser();
        $notification = $this->createNotification($owner);

        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/notifications/' . $notification->getPublicId()->toString(),
            $intruder,
        );

        $this->assertSame(204, $response->getStatusCode(), 'delete has no ownership check (bug).');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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
