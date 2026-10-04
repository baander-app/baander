<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ActivityHistoryPaginationTest extends TestCase
{
    public function testPagesUseDatabaseOffsetAndStableOrderWithinUser(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();
        $timestamp = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $playedIds = $this->seedActivities($user, 5, $timestamp);
        $unplayedIds = $this->seedActivities($user, 2, null);
        $this->seedActivities($otherUser, 3, $timestamp);
        rsort($playedIds, SORT_STRING);
        rsort($unplayedIds, SORT_STRING);
        // Preserve PostgreSQL DESC's existing NULL-first history order.
        $expectedIds = [...$unplayedIds, ...$playedIds];
        $this->entityManager->clear();

        $all = $this->history($user, 'limit=100');
        self::assertSame($expectedIds, array_column($all, 'uuid'));
        self::assertSame([$user->getId()->toString()], array_values(array_unique(array_column($all, 'userId'))));

        $pageIds = [];
        foreach ([0, 2, 4, 6] as $offset) {
            $page = $this->history($user, 'limit=2&offset=' . $offset);
            self::assertSame(array_slice($expectedIds, $offset, 2), array_column($page, 'uuid'));
            $pageIds = [...$pageIds, ...array_column($page, 'uuid')];
        }
        self::assertSame($expectedIds, $pageIds);
        self::assertCount(count($pageIds), array_unique($pageIds));
        self::assertSame([], $this->history($user, 'limit=2&offset=7'));
        self::assertSame([], $this->history($user, 'limit=2&offset=' . PHP_INT_MAX));
        self::assertSame($expectedIds, array_column($this->history($user, ''), 'uuid'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        foreach (['limit' => ['-1', '0', '101', 'abc', '1.5', '1e2', '', '999999999999999999999999'], 'offset' => ['-1', 'abc', '1.5', '1e2', '', '999999999999999999999999']] as $field => $values) {
            foreach ($values as $value) {
                yield $field . '=' . $value => [$field . '=' . $value, $field];
            }
            yield $field . ' array' => [$field . '[]=1', $field];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidPaginationReturnsBadRequest(string $query, string $field): void
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/activity/history?' . $query, $this->createTestUser()),
            400,
        );
        self::assertSame(400, $data['error']['code']);
        self::assertArrayHasKey($field, $data['error']['details']);
    }

    public function testHistoryRequiresAuthentication(): void
    {
        $this->assertJsonResponse($this->anonymousRequest('GET', '/api/activity/history?limit=2&offset=2'), 401);
    }

    /** @return list<string> */
    private function seedActivities(User $user, int $count, ?\DateTimeImmutable $lastPlayedAt): array
    {
        $repository = static::getContainer()->get(MediaActivityRepositoryInterface::class);
        $metadata = $this->entityManager->getClassMetadata(MediaActivityEntity::class);
        $ids = [];
        for ($index = 0; $index < $count; ++$index) {
            $activity = MediaActivity::create($user->getId(), 'play');
            $repository->save($activity);
            $entity = $this->entityManager->find(MediaActivityEntity::class, $activity->getId());
            self::assertInstanceOf(MediaActivityEntity::class, $entity);
            // Fix the timestamp through ORM metadata so ties are exact rather than timing-dependent.
            $metadata->setFieldValue($entity, 'lastPlayedAt', $lastPlayedAt);
            $ids[] = $activity->getId()->toString();
        }
        $this->entityManager->flush();

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function history(User $user, string $query): array
    {
        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/activity/history' . ($query === '' ? '' : '?' . $query), $user),
            200,
            'data',
        );

        return $data['data'];
    }
}
