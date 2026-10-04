<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotificationCursorPaginationTest extends TestCase
{
    private NotificationRepositoryInterface $notificationRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationRepository = static::getContainer()->get(NotificationRepositoryInterface::class);
    }

    /** @param list<int> $expectedPageSizes */
    #[DataProvider('pageSizes')]
    public function testReturnedCursorTraversesEveryNotification(int $count, array $expectedPageSizes): void
    {
        $user = $this->createTestUser();
        $notifications = [];
        for ($i = 0; $i < $count; ++$i) {
            $notifications[] = $this->createNotification($user);
        }

        $this->assertPagination($user, $notifications, ['limit' => 2], $expectedPageSizes);
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function pageSizes(): iterable
    {
        yield 'partial final page' => [5, [2, 2, 1]];
        yield 'exact final page' => [4, [2, 2]];
        yield 'empty result' => [0, [0]];
    }

    public function testCategoryAndUnreadFiltersApplyOnEveryPage(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();
        $matching = [];
        for ($i = 0; $i < 4; ++$i) {
            $matching[] = $this->createNotification($user);
            $this->createNotification($user, NotificationCategory::MediaChanges);
            $read = $this->createNotification($user);
            $this->notificationRepository->markAsRead($read->getId());
            $this->createNotification($otherUser);
        }

        $this->assertPagination(
            $user,
            $matching,
            ['limit' => 2, 'category' => 'security', 'unread' => 'true'],
            [2, 2],
        );
    }

    public function testCursorFromAnotherUserDoesNotExposeTheirNotifications(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();
        $owned = $this->createNotification($user);
        $this->createNotification($otherUser);
        $otherNewest = $this->createNotification($otherUser);

        $otherPage = $this->getPage($otherUser, ['limit' => 1]);
        $this->assertSame($otherNewest->getId()->toString(), $otherPage['nextCursor']);

        $page = $this->getPage($user, ['limit' => 1, 'cursor' => $otherPage['nextCursor']]);
        $this->assertSame([$owned->getPublicId()->toString()], array_column($page['data'], 'publicId'));
        $this->assertNull($page['nextCursor']);
    }

    /**
     * @param list<Notification> $notifications
     * @param array<string, int|string> $query
     * @param list<int> $expectedPageSizes
     */
    private function assertPagination(User $user, array $notifications, array $query, array $expectedPageSizes): void
    {
        usort($notifications, static fn (Notification $a, Notification $b): int => strcmp($b->getId()->toString(), $a->getId()->toString()));
        $seen = [];
        $offset = 0;
        foreach ($expectedPageSizes as $pageIndex => $pageSize) {
            $page = $this->getPage($user, $query);
            $this->assertCount($pageSize, $page['data']);
            $expected = array_slice($notifications, $offset, $pageSize);
            $this->assertSame(
                array_map(static fn (Notification $notification): string => $notification->getPublicId()->toString(), $expected),
                array_column($page['data'], 'publicId'),
            );
            foreach ($page['data'] as $item) {
                $this->assertArrayNotHasKey('id', $item);
                $seen[] = $item['publicId'];
            }
            $offset += $pageSize;

            if ($pageIndex === count($expectedPageSizes) - 1) {
                $this->assertNull($page['nextCursor']);
            } else {
                $last = $expected[array_key_last($expected)];
                $this->assertSame($last->getId()->toString(), $page['nextCursor']);
                $query['cursor'] = $page['nextCursor'];
            }
        }
        $this->assertCount(count($notifications), $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'Cursor pages must not repeat notifications.');
    }

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    private function getPage(User $user, array $query): array
    {
        $page = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/?' . http_build_query($query), $user),
            200,
            'data',
        );
        $this->assertArrayHasKey('nextCursor', $page);

        return $page;
    }

    private function createNotification(User $user, NotificationCategory $category = NotificationCategory::Security): Notification
    {
        $notification = Notification::create($user->getId(), $category, 'cursor.test', 'Test Title', 'Test Body');
        $this->notificationRepository->save($notification);

        return $notification;
    }
}
