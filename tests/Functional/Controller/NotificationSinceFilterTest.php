<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotificationSinceFilterTest extends TestCase
{
    private NotificationRepositoryInterface $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = static::getContainer()->get(NotificationRepositoryInterface::class);
    }

    #[DataProvider('equivalentInstants')]
    public function testSinceIsExclusiveAndScopedToAuthenticatedUser(string $since): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $this->notification($user, '2026-10-04T11:59:59Z');
        $this->notification($user, '2026-10-04T12:00:00Z');
        $after = $this->notification($user, '2026-10-04T12:00:01Z');
        $this->notification($other, '2026-10-04T12:00:02Z');

        $this->assertSame([$after->getPublicId()->toString()], $this->listedIds($user, ['since' => $since]));
    }

    /** @return iterable<string, array{string}> */
    public static function equivalentInstants(): iterable
    {
        yield 'UTC' => ['2026-10-04T12:00:00Z'];
        yield 'positive offset' => ['2026-10-04T14:00:00+02:00'];
        yield 'negative offset' => ['2026-10-04T06:30:00-05:30'];
        yield 'RFC 3339 large offset' => ['2026-10-05T11:00:00+23:00'];
    }

    public function testFractionalBoundaryWithNonUtcDatabaseSession(): void
    {
        $user = $this->createTestUser();
        $equal = $this->notification($user, '2026-10-04T12:00:00Z');
        $after = $this->notification($user, '2026-10-04T12:00:01Z');
        $connection = $this->entityManager->getConnection();
        $timezone = $connection->fetchOne('SHOW TIME ZONE');
        $columnType = $connection->fetchOne("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'notifications'::regclass AND attname = 'created_at'");
        $this->assertSame('timestamp(0) with time zone', $columnType);

        try {
            $connection->executeStatement("SET LOCAL TIME ZONE 'Asia/Kathmandu'");
            $this->assertSame(
                [$after->getPublicId()->toString(), $equal->getPublicId()->toString()],
                $this->listedIds($user, ['since' => '2026-10-04T13:59:59.999999+02:00']),
            );
            $this->assertSame(
                [$after->getPublicId()->toString()],
                $this->listedIds($user, ['since' => '2026-10-04T14:00:00.000001+02:00']),
            );
        } finally {
            $connection->executeQuery("SELECT set_config('TimeZone', ?, true)", [$timezone]);
        }
    }

    public function testSinceIntersectsCategoryUnreadCursorDirectionAndLimit(): void
    {
        $user = $this->createTestUser();
        $this->notification($user, '2026-10-04T11:59:59Z');
        $first = $this->notification($user, '2026-10-04T12:00:01Z');
        $this->notification($user, '2026-10-04T12:00:02Z', NotificationCategory::MediaChanges);
        $this->notification($user, '2026-10-04T12:00:03Z', isRead: true);
        $second = $this->notification($user, '2026-10-04T12:00:04Z');
        $third = $this->notification($user, '2026-10-04T12:00:05Z');
        $filters = ['since' => '2026-10-04T12:00:00Z', 'category' => 'security', 'unread' => 'true', 'limit' => 1];

        $this->assertSame([$third->getPublicId()->toString()], $this->listedIds($user, $filters));
        $this->assertSame([$second->getPublicId()->toString()], $this->listedIds($user, $filters + ['cursor' => $third->getId()->toString()]));
        $ascending = $this->notifications->findByUserId(
            $user->getId(),
            category: NotificationCategory::Security,
            unreadOnly: true,
            limit: 1,
            cursor: $first->getId()->toString(),
            direction: 'asc',
            since: new DateTimeImmutable('2026-10-04T12:00:00Z'),
        );
        $this->assertSame([$second->getPublicId()->toString()], array_map(
            static fn (Notification $notification): string => $notification->getPublicId()->toString(),
            $ascending,
        ));
    }

    public function testAbsentSincePreservesDefaultLimitOrderAndRepositoryDefaults(): void
    {
        $user = $this->createTestUser();
        $notifications = [];
        for ($index = 0; $index < 52; ++$index) {
            $notifications[] = $this->notification($user, '2026-10-04T12:00:00Z');
        }
        $expected = array_map(static fn (Notification $notification): string => $notification->getPublicId()->toString(), array_reverse($notifications));

        $this->assertSame(array_slice($expected, 0, 50), $this->listedIds($user));
        $this->assertSame($expected, array_map(
            static fn (Notification $notification): string => $notification->getPublicId()->toString(),
            $this->notifications->findByUserId($user->getId()),
        ));
    }

    /** @param array<string, int|string> $filters
     *  @return list<string>
     */
    private function listedIds(User $user, array $filters = []): array
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/notifications/?' . http_build_query($filters), $user),
            200,
            'data',
        );

        return array_column($data['data'], 'publicId');
    }

    private function notification(
        User $user,
        string $createdAt,
        NotificationCategory $category = NotificationCategory::Security,
        bool $isRead = false,
    ): Notification {
        $notification = Notification::reconstitute(
            Uuid::generate(), new PublicId(), $user->getId(), $category,
            'since.test', 'Since boundary', 'Deterministic notification fixture',
            $isRead, new DateTimeImmutable($createdAt),
        );
        $this->notifications->save($notification);
        // Save currently assigns entity creation time; set the fixture's historical instant explicitly.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE notifications SET created_at = ? WHERE id = ?',
            [$notification->getCreatedAt()->format('Y-m-d H:i:s.uP'), $notification->getId()->toString()],
            [ParameterType::STRING, ParameterType::STRING],
        );
        $this->entityManager->clear();

        return $notification;
    }
}
