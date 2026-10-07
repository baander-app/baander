<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Types\Types;

/**
 * The activity analytics count activities last played in [from, to). The API takes inclusive
 * calendar days, so ?from=D&to=D covers D from its first instant up to the start of D + 1.
 */
final class ActivityAdminDateRangeTest extends TestCase
{
    private User $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->createTestUser();
    }

    public function testADayCountsUpToButNotIncludingTheNextMidnight(): void
    {
        $this->play('2026-03-09 23:59:59', 1000);
        $this->play('2026-03-10 00:00:00', 1);
        // Recorded the way MediaActivityEntity is written: through the datetime_immutable type.
        $this->play('2026-03-10 23:59:59.5', 10);
        $this->play('2026-03-11 00:00:00', 100);

        $admin = $this->createAdminUser();
        foreach (['summary' => 'total_plays', 'engagement' => 'active_users'] as $endpoint => $key) {
            $response = $this->authenticatedRequest('GET', '/api/admin/activity/' . $endpoint . '?from=2026-03-10&to=2026-03-10', $admin);
            $data = $this->assertJsonResponse($response, 200, 'data')['data'];
            if ($endpoint === 'summary') {
                self::assertSame(11, $data[$key], 'The day keeps its first and last second and leaves out the next midnight.');
            } else {
                self::assertSame(1, $data[$key]);
                self::assertEqualsWithDelta(11.0, $data['avg_plays_per_user'], 0.001);
            }
        }

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary?from=2026-03-10&to=2026-03-11', $admin);
        self::assertSame(111, $this->assertJsonResponse($response, 200, 'data')['data']['total_plays']);

        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary?from=2026-03-11&to=2026-03-11', $admin);
        self::assertSame(100, $this->assertJsonResponse($response, 200, 'data')['data']['total_plays']);
    }

    public function testThePortExcludesTheEndInstant(): void
    {
        $this->play('2026-03-10 00:00:00', 1);
        $this->play('2026-03-11 00:00:00', 100);

        $summary = $this->analytics()->getSummary(
            new \DateTimeImmutable('2026-03-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-03-11T00:00:00+00:00'),
        );

        self::assertSame(1, $summary['total_plays']);
    }

    public function testThePortReadsBoundsInTheirOwnTimeZone(): void
    {
        // 00:30 on 10 March in Copenhagen (UTC+1).
        $this->play('2026-03-09 23:30:00', 1);

        $copenhagen = new \DateTimeZone('Europe/Copenhagen');
        $summary = $this->analytics()->getSummary(
            new \DateTimeImmutable('2026-03-10 00:00:00', $copenhagen),
            new \DateTimeImmutable('2026-03-11 00:00:00', $copenhagen),
        );

        self::assertSame(1, $summary['total_plays']);
    }

    private function play(string $lastPlayedAt, int $playCount): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->getConnection()->insert('media_activities', [
            'id' => (new Uuid())->toString(),
            'public_id' => (new PublicId())->toString(),
            'user_id' => $this->listener->getId()->toString(),
            'activity_type' => 'song',
            'play_count' => $playCount,
            'last_played_at' => new \DateTimeImmutable($lastPlayedAt, new \DateTimeZone('UTC')),
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'last_played_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function analytics(): ActivityAnalyticsPortInterface
    {
        return static::getContainer()->get(ActivityAnalyticsPortInterface::class);
    }
}
