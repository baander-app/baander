<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Model\TranscodeSessionState;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeSessionEntity;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class TranscodeSessionOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private ?Uuid $video = null;

    public function testOwnerIsAScalarUuidField(): void
    {
        $this->assertScalarUuidOwners([TranscodeSessionEntity::class => ['userId', 'user_id']]);
    }

    public function testDeclaredForeignKeyMatchesTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog(['transcode_sessions' => ['user_id', 'fk_transcode_sessions_user_id']]);
    }

    public function testSchemaComparisonIsCleanForTranscodeSessions(): void
    {
        $this->assertSchemaComparisonIsClean(['transcode_sessions']);
    }

    public function testSchemaComparisonIsCleanForMediaRuntimeAuthorities(): void
    {
        $this->assertSchemaComparisonIsClean(['transcode_runtime_state', 'audio_rendition_runtime']);
    }

    public function testLookupsByUserAndByJobStayOwnerScoped(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $shared = $this->createJob();
        $own = $this->createJob();
        $repository = new TranscodeSessionRepository($this->manager);

        $firstShared = $this->session($first, $shared);
        $secondShared = $this->session($second, $shared);
        $firstOwn = $this->session($first, $own);
        $firstOwn->markPreparing();
        $firstDone = $this->session($first, $own);
        $firstDone->markCancelled();
        foreach ([$firstShared, $secondShared, $firstOwn, $firstDone] as $session) {
            $repository->save($session);
        }
        $this->manager->clear();

        $ids = static function (array $sessions): array {
            $values = array_map(static fn (TranscodeSession $session): string => $session->getId()->toString(), $sessions);
            sort($values);

            return $values;
        };
        $expected = static function (TranscodeSession ...$sessions) use ($ids): array {
            return $ids($sessions);
        };

        self::assertSame($expected($firstShared, $firstOwn, $firstDone), $ids($repository->findByUser($first)));
        self::assertSame($expected($secondShared), $ids($repository->findByUser($second)));
        self::assertSame($expected($firstShared, $firstOwn), $ids($repository->findActiveSessions($first)));
        self::assertSame($expected($firstShared, $secondShared), $ids($repository->findByJob($shared)));
        $created = $ids([$firstShared, $secondShared, $firstOwn, $firstDone]);
        self::assertSame(
            $expected($firstShared, $secondShared, $firstOwn),
            array_values(array_intersect($ids($repository->findAllActiveSessions()), $created)),
        );

        foreach ($repository->findByJob($own) as $session) {
            self::assertTrue($session->getUserId()->equals($first));
            self::assertTrue($session->getJobId()->equals($own));
        }
        $loaded = $repository->findByUuid($secondShared->getId());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->getUserId()->equals($second));
        self::assertTrue($loaded->getJobId()->equals($shared));
    }

    public function testUserDeletionCascadesOnlyThatUsersSessions(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $job = $this->createJob();
        $repository = new TranscodeSessionRepository($this->manager);
        foreach ([$first, $second] as $owner) {
            $repository->save($this->session($owner, $job));
        }
        $this->manager->clear();

        $this->deleteUser($first);

        self::assertSame(0, $this->countOwnedRows('transcode_sessions', 'user_id', $first));
        self::assertSame(1, $this->countOwnedRows('transcode_sessions', 'user_id', $second));
    }

    public function testForeignKeyRejectsAnUnknownOwner(): void
    {
        $job = $this->createJob();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        (new TranscodeSessionRepository($this->manager))->save($this->session(Uuid::generate(), $job));
    }

    public function testJobIsRequiredByMappingAndCatalog(): void
    {
        $mapped = (new SchemaTool($this->manager))->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        self::assertTrue($mapped->getTable('transcode_sessions')->getColumn('job_id')->getNotnull());
        self::assertTrue($this->manager->getConnection()->createSchemaManager()->introspectTable('transcode_sessions')->getColumn('job_id')->getNotnull());

        $this->expectException(NotNullConstraintViolationException::class);

        $this->insertSessionRow($this->createUser(), null);
    }

    public function testNewAndUnchangedSessionsPersistTheAggregatesTimestamps(): void
    {
        $createdAt = new \DateTimeImmutable('2023-11-05 01:02:03+00:00');
        $updatedAt = new \DateTimeImmutable('2024-02-29 13:14:15+00:00');
        $session = TranscodeSession::reconstitute(new TranscodeSessionState(
            id: Uuid::generate(),
            publicId: new PublicId(),
            userId: $this->createUser(),
            jobId: $this->createJob(),
            videoId: $this->video(),
            state: SessionState::Active,
            priority: SessionPriority::Normal,
            audioProfile: AudioProfile::streamingStereo(),
            currentSegmentIndex: 3,
            wallClockOffset: 1.5,
            metrics: [],
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        ));
        $repository = new TranscodeSessionRepository($this->manager);

        $repository->save($session);
        $this->manager->clear();
        $loaded = $repository->findByUuid($session->getId());
        self::assertNotNull($loaded);
        self::assertSame($createdAt->getTimestamp(), $loaded->getCreatedAt()->getTimestamp());
        self::assertSame($updatedAt->getTimestamp(), $loaded->getUpdatedAt()->getTimestamp());

        // Saving the unchanged aggregate through the managed entity keeps its timestamps.
        $repository->save($loaded);
        $this->manager->clear();
        $reloaded = $repository->findByUuid($session->getId());
        self::assertNotNull($reloaded);
        self::assertSame($createdAt->getTimestamp(), $reloaded->getCreatedAt()->getTimestamp());
        self::assertSame($updatedAt->getTimestamp(), $reloaded->getUpdatedAt()->getTimestamp());
    }

    private function insertSessionRow(Uuid $owner, ?Uuid $job): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('transcode_sessions', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'user_id' => $owner->toString(),
            'job_id' => $job?->toString(),
            'video_id' => $this->video()->toString(),
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);

        return $id;
    }

    private function createJob(): Uuid
    {
        $job = new TranscodeJobEntity(new PublicId(), $this->createVideo(), '1080p');
        $this->manager->persist($job);
        $this->manager->flush();

        return $job->getId();
    }

    private function session(Uuid $owner, Uuid $job): TranscodeSession
    {
        return TranscodeSession::create($owner, $job, $this->video(), AudioProfile::streamingStereo());
    }

    /** An existing video for sessions; each job has its own, as a video has one job per quality tier. */
    private function video(): Uuid
    {
        return $this->video ??= $this->createVideo();
    }
}
