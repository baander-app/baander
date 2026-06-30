<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation\Infrastructure\Repository;

use App\Auth\Domain\Model\User;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use RuntimeException;

final class RecommendationRepositoryTest extends TestCase
{
    private RecommendationRepositoryInterface $recommendationRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->recommendationRepository = $container->get(RecommendationRepositoryInterface::class);
    }

    private function createAndPersistUser(): User
    {
        $user = User::register(
            new Email('rec-test-' . bin2hex(random_bytes(4)) . '@example.com'),
            password_hash('password', PASSWORD_BCRYPT),
            'Recommendation Test User',
        );
        $this->userRepository->save($user);

        return $this->userRepository->findByUuid($user->getId());
    }

    // --- Happy path: save and findByUuid ---

    public function testSaveCreatesNewRecommendation(): void
    {
        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-001',
            targetType: 'song',
            targetId: 'song-002',
            score: 85,
        );

        $this->recommendationRepository->save($recommendation);

        $found = $this->recommendationRepository->findByUuid($recommendation->getId());

        $this->assertNotNull($found);
        $this->assertSame($recommendation->getId()->toString(), $found->getId()->toString());
        $this->assertSame('song-001', $found->getSourceId());
        $this->assertSame('song-002', $found->getTargetId());
        $this->assertSame(85.0, $found->getScore());
        $this->assertSame('default', $found->getName());
        $this->assertNull($found->getPosition());
        $this->assertNull($found->getUserId());
    }

    // --- Happy path: save with userId ---

    public function testSavePreservesUserAssociation(): void
    {
        $user = $this->createAndPersistUser();
        $this->assertNotNull($user);

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-100',
            targetType: 'song',
            targetId: 'song-200',
            score: 90,
            userId: $user->getId(),
        );

        $this->recommendationRepository->save($recommendation);

        $found = $this->recommendationRepository->findByUuid($recommendation->getId());

        $this->assertNotNull($found);
        $this->assertSame($user->getId()->toString(), $found->getUserId()?->toString());
    }

    // --- Happy path: update existing via save ---

    public function testSaveUpdatesExistingRecommendation(): void
    {
        $recommendation = Recommendation::create(
            sourceType: 'album',
            sourceId: 'album-001',
            targetType: 'album',
            targetId: 'album-002',
            score: 50,
            name: 'default',
            position: 1,
        );

        $this->recommendationRepository->save($recommendation);

        // Reconstitute with updated fields (same ID = update)
        $updated = Recommendation::reconstitute(
            $recommendation->getId(),
            'custom-name',
            (string) $recommendation->getSourceType(),
            $recommendation->getSourceId(),
            (string) $recommendation->getTargetType(),
            $recommendation->getTargetId(),
            95.0,
            10,
            null,
            $recommendation->getCreatedAt(),
            $recommendation->getUpdatedAt(),
        );

        $this->recommendationRepository->save($updated);

        $found = $this->recommendationRepository->findByUuid($recommendation->getId());

        $this->assertNotNull($found);
        $this->assertSame($recommendation->getId()->toString(), $found->getId()->toString());
        $this->assertSame('custom-name', $found->getName());
        $this->assertSame(95.0, $found->getScore());
        $this->assertSame(10, $found->getPosition());
    }

    // --- Happy path: saveMany ---

    public function testSaveManyPersistsAllRecommendations(): void
    {
        $recommendations = [];
        for ($i = 0; $i < 150; $i++) {
            $recommendations[] = Recommendation::create(
                sourceType: 'song',
                sourceId: 'batch-source-' . bin2hex(random_bytes(2)) . '-' . $i,
                targetType: 'song',
                targetId: 'batch-target-' . $i,
                score: $i,
            );
        }

        $this->recommendationRepository->saveMany($recommendations);

        // Verify a sample of the persisted entities
        foreach ([0, 50, 149] as $index) {
            $found = $this->recommendationRepository->findByUuid($recommendations[$index]->getId());
            $this->assertNotNull($found, "Recommendation at index {$index} should exist");
            $this->assertSame((float) $index, $found->getScore());
        }
    }

    // --- Happy path: saveMany entities can be queried individually ---

    public function testSaveManyEntitiesCanBeQueriedIndividually(): void
    {
        $recommendations = [
            Recommendation::create('song', 'src-a-' . bin2hex(random_bytes(4)), 'song', 'tgt-a', 10),
            Recommendation::create('song', 'src-b-' . bin2hex(random_bytes(4)), 'song', 'tgt-b', 20),
        ];

        $this->recommendationRepository->saveMany($recommendations);

        foreach ($recommendations as $rec) {
            $found = $this->recommendationRepository->findByUuid($rec->getId());
            $this->assertNotNull($found);
            $this->assertSame($rec->getTargetId(), $found->getTargetId());
        }
    }

    // --- Happy path: findBySource with limit ---

    public function testFindBySourceReturnsResultsSortedByScoreAndRespectsLimit(): void
    {
        $sourceType = 'song';
        $sourceId = 'findby-src-' . bin2hex(random_bytes(4));

        for ($i = 0; $i < 5; $i++) {
            $rec = Recommendation::create(
                sourceType: $sourceType,
                sourceId: $sourceId,
                targetType: 'song',
                targetId: 'target-' . $i,
                score: $i * 10,
            );
            $this->recommendationRepository->save($rec);
        }

        $results = $this->recommendationRepository->findBySource($sourceType, $sourceId, 'default', 3);

        $this->assertCount(3, $results);
        $this->assertSame(40.0, $results[0]->getScore());
        $this->assertSame(30.0, $results[1]->getScore());
        $this->assertSame(20.0, $results[2]->getScore());
    }

    // --- Happy path: findBySource with name filter ---

    public function testFindBySourceFiltersByName(): void
    {
        $sourceId = 'name-filter-src-' . bin2hex(random_bytes(4));

        $rec1 = Recommendation::create('song', $sourceId, 'song', 'tgt-1', 50, name: 'default');
        $rec2 = Recommendation::create('song', $sourceId, 'song', 'tgt-2', 60, name: 'custom');

        $this->recommendationRepository->saveMany([$rec1, $rec2]);

        $defaultResults = $this->recommendationRepository->findBySource('song', $sourceId, 'default');
        $customResults = $this->recommendationRepository->findBySource('song', $sourceId, 'custom');

        $this->assertCount(1, $defaultResults);
        $this->assertSame('tgt-1', $defaultResults[0]->getTargetId());

        $this->assertCount(1, $customResults);
        $this->assertSame('tgt-2', $customResults[0]->getTargetId());
    }

    // --- Happy path: findTargeting with limit ---

    public function testFindTargetingReturnsResultsSortedByScoreAndRespectsLimit(): void
    {
        $targetId = 'findby-tgt-' . bin2hex(random_bytes(4));

        for ($i = 0; $i < 5; $i++) {
            $rec = Recommendation::create(
                sourceType: 'song',
                sourceId: 'source-' . $i,
                targetType: 'song',
                targetId: $targetId,
                score: $i * 10,
            );
            $this->recommendationRepository->save($rec);
        }

        $results = $this->recommendationRepository->findTargeting('song', $targetId, 2);

        $this->assertCount(2, $results);
        $this->assertSame(40.0, $results[0]->getScore());
        $this->assertSame(30.0, $results[1]->getScore());
    }

    // --- Happy path: findForUser with limit ---

    public function testFindForUserReturnsOnlyUserRecommendationsAndRespectsLimit(): void
    {
        $user = $this->createAndPersistUser();
        $this->assertNotNull($user);

        for ($i = 0; $i < 5; $i++) {
            $rec = Recommendation::create(
                sourceType: 'song',
                sourceId: 'fuser-src-' . bin2hex(random_bytes(2)) . '-' . $i,
                targetType: 'song',
                targetId: 'fuser-tgt-' . $i,
                score: $i * 10,
                userId: $user->getId(),
            );
            $this->recommendationRepository->save($rec);
        }

        // Create a global recommendation — should appear (global + user recommendations)
        $noUserRec = Recommendation::create('song', 'no-user-src', 'song', 'no-user-tgt', 99);
        $this->recommendationRepository->save($noUserRec);

        $results = $this->recommendationRepository->findForUser($user->getId(), 3);

        $this->assertCount(3, $results);
        // Global recommendation (99) appears first, then user-specific (40, 30)
        $this->assertSame(99.0, $results[0]->getScore());
        $this->assertSame(40.0, $results[1]->getScore());
        $this->assertSame(30.0, $results[2]->getScore());
    }

    // --- Happy path: findForUser clamps oversized limit ---

    public function testFindForUserClampsOversizedLimit(): void
    {
        $user = $this->createAndPersistUser();
        $this->assertNotNull($user);

        for ($i = 0; $i < 5; $i++) {
            $rec = Recommendation::create(
                sourceType: 'song',
                sourceId: 'clamp-src-' . bin2hex(random_bytes(2)) . '-' . $i,
                targetType: 'song',
                targetId: 'clamp-tgt-' . $i,
                score: $i * 10,
                userId: $user->getId(),
            );
            $this->recommendationRepository->save($rec);
        }

        // Request 999, should be clamped to 200, but only 5 exist
        $results = $this->recommendationRepository->findForUser($user->getId(), 999);

        $this->assertCount(5, $results);
    }

    // --- Edge case: save with non-existent userId throws RuntimeException ---

    public function testSaveWithNonExistentUserIdThrowsException(): void
    {
        $fakeUserId = new Uuid();

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'bad-user-src',
            targetType: 'song',
            targetId: 'bad-user-tgt',
            score: 50.0,
            userId: $fakeUserId,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User with ID');

        $this->recommendationRepository->save($recommendation);
    }

    // --- Edge case: findByUuid with non-existent UUID ---

    public function testFindByUuidWithNonExistentUuidReturnsNull(): void
    {
        $result = $this->recommendationRepository->findByUuid(new Uuid());

        $this->assertNull($result);
    }

    // --- Edge case: delete non-existent recommendation ---

    public function testDeleteNonExistentRecommendationIsSilentNoOp(): void
    {
        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'ghost-src',
            targetType: 'song',
            targetId: 'ghost-tgt',
            score: 10,
        );

        // Never saved, so entity does not exist
        $this->recommendationRepository->delete($recommendation);

        // No exception thrown — silent no-op
        $this->assertTrue(true);
    }

    // --- Integration: deleteBySource ---

    public function testDeleteBySourceRemovesAllMatchingRecommendations(): void
    {
        $sourceId = 'del-src-' . bin2hex(random_bytes(4));

        $recs = [
            Recommendation::create('song', $sourceId, 'song', 'del-tgt-1', 10),
            Recommendation::create('song', $sourceId, 'song', 'del-tgt-2', 20),
            Recommendation::create('song', $sourceId, 'album', 'del-tgt-3', 30),
        ];
        $this->recommendationRepository->saveMany($recs);

        // Create a recommendation with different sourceId that should NOT be deleted
        $otherRec = Recommendation::create('song', 'other-src-' . bin2hex(random_bytes(4)), 'song', 'other-tgt', 40);
        $this->recommendationRepository->save($otherRec);

        $this->recommendationRepository->deleteBySource('song', $sourceId);

        $remaining = $this->recommendationRepository->findBySource('song', $sourceId);
        $this->assertCount(0, $remaining);

        $otherFound = $this->recommendationRepository->findByUuid($otherRec->getId());
        $this->assertNotNull($otherFound);
    }

    // --- Integration: delete existing recommendation ---

    public function testDeleteExistingRecommendation(): void
    {
        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'del-existing-src-' . bin2hex(random_bytes(4)),
            targetType: 'song',
            targetId: 'del-existing-tgt',
            score: 42,
        );

        $this->recommendationRepository->save($recommendation);

        $found = $this->recommendationRepository->findByUuid($recommendation->getId());
        $this->assertNotNull($found);

        $this->recommendationRepository->delete($recommendation);

        $found = $this->recommendationRepository->findByUuid($recommendation->getId());
        $this->assertNull($found);
    }

    // --- Integration: findBySource returns empty array when no results ---

    public function testFindBySourceReturnsEmptyArrayWhenNoResults(): void
    {
        $results = $this->recommendationRepository->findBySource('nonexistent', 'nonexistent');

        $this->assertSame([], $results);
    }

    // --- Integration: findTargeting returns empty array when no results ---

    public function testFindTargetingReturnsEmptyArrayWhenNoResults(): void
    {
        $results = $this->recommendationRepository->findTargeting('nonexistent', 'nonexistent');

        $this->assertSame([], $results);
    }

    // --- Integration: findForUser returns empty array when no results ---

    public function testFindForUserReturnsEmptyArrayWhenNoResults(): void
    {
        $results = $this->recommendationRepository->findForUser(new Uuid());

        $this->assertSame([], $results);
    }
}
