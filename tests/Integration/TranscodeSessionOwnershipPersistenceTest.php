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

    public function testJobsForTheSameVideoAndTierRemainScopedToTheirOwner(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $video = $this->createVideo();
        $tier = \App\Transcode\Domain\ValueObject\QualityTier::p720();
        $first = \App\Transcode\Domain\Model\TranscodeJob::create($video, $tier, '/fixture/alice', $alice);
        $second = \App\Transcode\Domain\Model\TranscodeJob::create($video, $tier, '/fixture/bob', $bob);
        $repository = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $repository->save($first);
        $repository->save($second);
        $unowned = new TranscodeJobEntity(new PublicId(), $video, $tier->name, ownerUserId: null);
        $this->manager->persist($unowned);
        $this->manager->flush();
        $this->manager->clear();
        self::assertTrue($repository->findByOwnerVideoAndQuality($alice, $video, $tier->name, requestId: $first->getRequestId())?->getId()->equals($first->getId()));
        self::assertTrue($repository->findByOwnerVideoAndQuality($bob, $video, $tier->name, requestId: $second->getRequestId())?->getId()->equals($second->getId()));
        self::assertSame([$first->getId()->toString()], array_map(static fn(\App\Transcode\Domain\Model\TranscodeJob $job): string => $job->getId()->toString(), $repository->findActiveByOwnerAndVideo($alice, $video)));
        self::assertNull($repository->findByUuid($unowned->getId())?->getOwnerUserId());
    }

    public function testPresentationIdentityIsPersistedAndSeparatesSelectedAudioStreams(): void
    {
        $owner = $this->createUser();
        $video = $this->createVideo();
        $repository = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $firstSelection = new \App\Transcode\Domain\ValueObject\PresentationSelection('copy', 'source-hash', 2, 4, 'mp4');
        $secondSelection = new \App\Transcode\Domain\ValueObject\PresentationSelection('copy', 'source-hash', 2, 5, 'mp4');
        $first = \App\Transcode\Domain\Model\TranscodeJob::createCopy($video, '/fixture/copy-first', $owner, $firstSelection, 1920, 1080);
        $second = \App\Transcode\Domain\Model\TranscodeJob::createCopy($video, '/fixture/copy-second', $owner, $secondSelection, 1920, 1080);
        $repository->save($first);
        $repository->save($second);
        $this->manager->clear();
        self::assertSame($firstSelection->jsonSerialize(), $repository->findByUuid($first->getId())?->getPresentationSelection()?->jsonSerialize());
        self::assertTrue($repository->findByOwnerVideoAndQuality($owner, $video, 'copy', $firstSelection->canonicalKey(), $first->getRequestId())?->getId()->equals($first->getId()));
        self::assertTrue($repository->findByOwnerVideoAndQuality($owner, $video, 'copy', $secondSelection->canonicalKey(), $second->getRequestId())?->getId()->equals($second->getId()));
        self::assertNull($repository->findByOwnerVideoAndQuality($owner, $video, 'copy'));
        $this->assertSchemaComparisonIsClean(['transcode_jobs']);
    }

    public function testCurrentSessionsAreIsolatedByLogicalPlaybackRequest(): void
    {
        $owner = $this->createUser();
        $video = $this->createVideo();
        $selection = new \App\Transcode\Domain\ValueObject\PresentationSelection('copy', 'source-hash', 2, 4, 'mp4');
        $jobs = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $sessions = new TranscodeSessionRepository($this->manager);
        $connection = $this->manager->getConnection();
        $transaction = new class($connection) implements \App\Shared\Application\Port\TransactionPortInterface {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
            public function run(callable $operation): mixed { return $this->connection->transactional(static fn (): mixed => $operation()); }
        };
        $runtime = new \App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState($connection, $transaction);
        $expected = [];
        foreach (['first-client', 'second-client'] as $request) {
            $job = \App\Transcode\Domain\Model\TranscodeJob::createCopy($video, '/fixture/' . $request, $owner, $selection, 1920, 1080, $request);
            $jobs->save($job);
            $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
            $sessions->save($session);
            $runtime->admit($job->getId(), $session->getId(), $request, new \App\Transcode\Application\DTO\DesiredTranscodeState(AudioProfile::streamingStereo(), presentationSelection: $selection));
            $expected[$request] = [$job->getPublicId()->toString() => $session->getPublicId()->toString()];
        }
        $security = $this->createStub(\Symfony\Bundle\SecurityBundle\Security::class);
        $security->method('getUser')->willReturn(new \App\Auth\Infrastructure\Security\SecurityUser($owner->toString(), 'viewer@baander.app', ''));
        $playback = new \App\Transcode\Infrastructure\Auth\AuthorizedPlayback($security, $connection, $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class), new \Symfony\Component\Clock\NativeClock());
        foreach ($expected as $request => $sessions) {
            self::assertSame($sessions, array_map(static fn (PublicId $id): string => $id->toString(), $playback->findSessions($video, $request, [PublicId::fromString(array_key_first($sessions))])));
            self::assertSame([], $playback->findSessions($video, $request, []));
        }
        self::assertSame([], $playback->findSessions($video, 'unknown-client', [PublicId::fromString(array_key_first($expected['first-client']))]));
    }

    public function testPlaybackControlsChangeOnlyTheMatchingClientTiers(): void
    {
        $owner = $this->createUser();
        $video = $this->createVideo();
        $jobs = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $sessions = new TranscodeSessionRepository($this->manager);
        $connection = $this->manager->getConnection();
        $transaction = new class($connection) implements \App\Shared\Application\Port\TransactionPortInterface {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
            public function run(callable $operation): mixed { return $this->connection->transactional(static fn (): mixed => $operation()); }
        };
        $runtime = new \App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState($connection, $transaction);
        $control = new \App\Transcode\Application\Service\TranscodePlaybackControl(
            new \App\Transcode\Infrastructure\Transcode\TranscodeSessionService($sessions),
            $runtime,
            $transaction,
            new \App\Transcode\Infrastructure\Transcode\TranscodeJobService($jobs, $this->createStub(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher(), $runtime),
        );
        $controlled = [];
        $protected = [];
        $mismatchedSession = null;
        foreach ([
            ['first-client', 'first-client', \App\Transcode\Domain\ValueObject\QualityTier::p360()],
            ['first-client', 'first-client', \App\Transcode\Domain\ValueObject\QualityTier::p720()],
            ['second-client', 'second-client', \App\Transcode\Domain\ValueObject\QualityTier::p360()],
            ['second-client', 'second-client', \App\Transcode\Domain\ValueObject\QualityTier::p720()],
            ['first-client', 'mismatched-client', \App\Transcode\Domain\ValueObject\QualityTier::p1080()],
        ] as [$jobRequest, $runtimeRequest, $tier]) {
            $job = \App\Transcode\Domain\Model\TranscodeJob::create($video, $tier, '/fixture/' . $jobRequest . '/' . $tier->name, $owner, requestId: $jobRequest);
            $job->attachSession();
            $job->markInProgress();
            $jobs->save($job);
            $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
            $session->markPreparing();
            $session->markActive();
            $sessions->save($session);
            $runtime->admit($job->getId(), $session->getId(), $runtimeRequest, new \App\Transcode\Application\DTO\DesiredTranscodeState(AudioProfile::streamingStereo()));
            if ($jobRequest === 'first-client' && $runtimeRequest === $jobRequest) {
                $controlled[] = [$job->getId(), $session->getId()];
            } else {
                $protected[$job->getId()->toString()] = $this->playbackRows($job->getId());
                if ($runtimeRequest !== $jobRequest) {
                    $mismatchedSession = $session;
                }
            }
        }
        self::assertNotNull($mismatchedSession);
        $allBefore = [];
        foreach ($controlled as [$jobId]) {
            $allBefore[$jobId->toString()] = $this->playbackRows($jobId);
        }
        self::assertNull($control->apply($mismatchedSession->getId(), 'ignored-control', status: 'paused'));
        foreach ($allBefore as $jobId => $snapshot) {
            self::assertSame($snapshot, $this->playbackRows(Uuid::fromString($jobId)), 'A mismatched source cannot change another client tier.');
        }

        foreach (['pause', 'seek', 'cancel'] as $operation) {
            match ($operation) {
                'pause' => $control->apply($controlled[0][1], 'first-client-pause', status: 'paused'),
                'seek' => $control->apply($controlled[0][1], 'first-client-seek', position: 17.25, seek: true),
                'cancel' => $control->apply($controlled[0][1], 'first-client-cancel', status: 'cancelled'),
            };
            foreach ($protected as $jobId => $snapshot) {
                self::assertSame($snapshot, $this->playbackRows(Uuid::fromString($jobId)), 'Other clients and mismatched runtime identities stay byte-for-byte unchanged.');
            }
            foreach ($controlled as [$jobId, $sessionId]) {
                $observed = $runtime->find($jobId);
                $session = $sessions->findByUuid($sessionId);
                $job = $jobs->findByUuid($jobId);
                self::assertNotNull($observed);
                self::assertNotNull($session);
                self::assertNotNull($job);
                self::assertSame('first-client', $job->getRequestId());
                self::assertSame($job->getRequestId(), $observed->requestId);
                self::assertSame($operation === 'cancel' ? 'cancelled' : 'paused', $observed->desired->status);
                self::assertSame($operation === 'cancel' ? SessionState::Cancelled : SessionState::Paused, $session->getSessionState());
                self::assertSame($operation === 'pause' ? 0.0 : 17.25, $observed->desired->position);
                self::assertSame($observed->desired->position, $session->getWallClockOffset());
                self::assertSame($operation === 'pause' ? 1 : 2, $observed->generation);
                self::assertSame($operation === 'cancel' ? 0 : 1, $job->getReferenceCount());
                self::assertSame($operation === 'cancel' ? 'cancelled' : 'in_progress', $job->getStatus()->value);
            }
        }
    }

    public function testAbandonedSessionExpiresAndItsJobIsReclaimedWhileAFreshSessionStays(): void
    {
        $owner = $this->createUser();
        $video = $this->createVideo();
        $jobs = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $sessions = new TranscodeSessionRepository($this->manager);
        $connection = $this->manager->getConnection();
        $transaction = new class($connection) implements \App\Shared\Application\Port\TransactionPortInterface {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
            public function run(callable $operation): mixed { return $this->connection->transactional(static fn (): mixed => $operation()); }
        };
        $runtime = new \App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState($connection, $transaction);
        $storage = $this->createMock(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class);
        $jobService = new \App\Transcode\Infrastructure\Transcode\TranscodeJobService($jobs, $storage, new \Symfony\Component\EventDispatcher\EventDispatcher(), $runtime);
        $expiry = new \App\Transcode\Application\Service\StaleTranscodeSessionExpiry(
            new \App\Transcode\Infrastructure\Transcode\TranscodeSessionService($sessions), $runtime, $jobService, $transaction, new \Symfony\Component\Clock\NativeClock(), new \Psr\Log\NullLogger(),
        );
        $handler = new \App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler($jobService, $expiry);
        $ids = [];
        foreach (['abandoned' => \App\Transcode\Domain\ValueObject\QualityTier::p360(), 'fresh' => \App\Transcode\Domain\ValueObject\QualityTier::p720(), 'paused' => \App\Transcode\Domain\ValueObject\QualityTier::p1080()] as $name => $tier) {
            $job = \App\Transcode\Domain\Model\TranscodeJob::create($video, $tier, '/fixture/' . $name, $owner, requestId: $name . '-client');
            $job->attachSession();
            $job->markInProgress();
            $jobs->save($job);
            $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
            $session->markPreparing();
            $session->markActive();
            $sessions->save($session);
            $connection->transactional(static fn () => $runtime->admit($job->getId(), $session->getId(), $name . '-client', new \App\Transcode\Application\DTO\DesiredTranscodeState(AudioProfile::streamingStereo(), position: 118.86)));
            $ids[$name] = [$job->getId(), $session->getId(), $session->getPublicId()];
        }
        // The abandoned client last reported or signed just over a day ago.
        $connection->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '24 hours 1 minute' WHERE id = ?", [$ids['abandoned'][1]->toString()]);
        $connection->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '23 hours' WHERE id = ?", [$ids['fresh'][1]->toString()]);
        // The paused client was last written just as long ago, but it still signs its stream URL.
        $connection->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '24 hours 1 minute' WHERE id = ?", [$ids['paused'][1]->toString()]);
        $security = $this->createStub(\Symfony\Bundle\SecurityBundle\Security::class);
        $security->method('getUser')->willReturn(new \App\Auth\Infrastructure\Security\SecurityUser($owner->toString(), 'viewer@baander.app', ''));
        $playback = new \App\Transcode\Infrastructure\Auth\AuthorizedPlayback($security, $connection, $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class), new \Symfony\Component\Clock\NativeClock());
        $playback->touchSessions([$ids['paused'][2]]);
        self::assertTrue((bool) $connection->fetchOne("SELECT updated_at > clock_timestamp() - interval '1 minute' FROM transcode_sessions WHERE id = ?", [$ids['paused'][1]->toString()]));
        // A session written within the last hour is left alone: a signing beside a playback report takes no row lock.
        $connection->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '30 minutes' WHERE id = ?", [$ids['paused'][1]->toString()]);
        $playback->touchSessions([$ids['paused'][2]]);
        self::assertTrue((bool) $connection->fetchOne("SELECT updated_at < clock_timestamp() - interval '29 minutes' FROM transcode_sessions WHERE id = ?", [$ids['paused'][1]->toString()]));
        $this->manager->clear();
        $storage->expects(self::once())->method('deleteIfUnretained')->with('/fixture/abandoned')->willReturn(true);

        self::assertSame(['expired' => 1, 'cleaned' => 1], $handler(new \App\Transcode\Application\Command\CleanupOrphanedJobsCommand()));

        $this->manager->clear();
        // The job went with its output; its session and execution rows cascade with it.
        self::assertNull($jobs->findByUuid($ids['abandoned'][0]));
        self::assertNull($sessions->findByUuid($ids['abandoned'][1]));
        self::assertNull($runtime->find($ids['abandoned'][0]));
        $kept = $runtime->find($ids['fresh'][0]);
        self::assertNotNull($kept);
        self::assertSame('running', $kept->desired->status);
        self::assertSame('pending', $kept->outcome);
        self::assertSame(SessionState::Active, $sessions->findByUuid($ids['fresh'][1])?->getSessionState());
        self::assertSame(1, $jobs->findByUuid($ids['fresh'][0])?->getReferenceCount());
        self::assertSame(SessionState::Active, $sessions->findByUuid($ids['paused'][1])?->getSessionState());
        self::assertSame(1, $jobs->findByUuid($ids['paused'][0])?->getReferenceCount());
    }

    public function testExpiryCancelsTheExecutionAWorkerHoldsAndCleanupWaitsForTheWorkerToLetGo(): void
    {
        $owner = $this->createUser();
        $video = $this->createVideo();
        $jobs = new \App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository($this->manager);
        $sessions = new TranscodeSessionRepository($this->manager);
        $connection = $this->manager->getConnection();
        $transaction = new class($connection) implements \App\Shared\Application\Port\TransactionPortInterface {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
            public function run(callable $operation): mixed { return $this->connection->transactional(static fn (): mixed => $operation()); }
        };
        $runtime = new \App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState($connection, $transaction);
        $storage = $this->createMock(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class);
        $jobService = new \App\Transcode\Infrastructure\Transcode\TranscodeJobService($jobs, $storage, new \Symfony\Component\EventDispatcher\EventDispatcher(), $runtime);
        $handler = new \App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler($jobService, new \App\Transcode\Application\Service\StaleTranscodeSessionExpiry(
            new \App\Transcode\Infrastructure\Transcode\TranscodeSessionService($sessions), $runtime, $jobService, $transaction, new \Symfony\Component\Clock\NativeClock(), new \Psr\Log\NullLogger(),
        ));
        $job = \App\Transcode\Domain\Model\TranscodeJob::create($video, \App\Transcode\Domain\ValueObject\QualityTier::p1080(), '/fixture/held', $owner, requestId: 'held-client');
        $job->attachSession();
        $job->markInProgress();
        $jobs->save($job);
        $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
        $session->markPreparing();
        $session->markActive();
        $sessions->save($session);
        $connection->transactional(static fn () => $runtime->admit($job->getId(), $session->getId(), 'held-client', new \App\Transcode\Application\DTO\DesiredTranscodeState(AudioProfile::streamingStereo())));
        // A worker holds the execution with a live lease, as it does at the lead throttle.
        $claim = $runtime->claim($job->getId(), new \App\Transcode\Application\DTO\MediaWorkerIdentity('baander.app:workers', str_repeat('a', 32), 'media', 1));
        self::assertNotNull($claim);
        $connection->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '2 days' WHERE id = ?", [$session->getId()->toString()]);
        $this->manager->clear();
        $storage->expects(self::once())->method('deleteIfUnretained')->with('/fixture/held')->willReturn(true);

        self::assertSame(['expired' => 1, 'cleaned' => 0], $handler(new \App\Transcode\Application\Command\CleanupOrphanedJobsCommand()));

        $this->manager->clear();
        $cancelled = $runtime->find($job->getId());
        self::assertNotNull($cancelled);
        self::assertSame('cancelled', $cancelled->desired->status);
        self::assertSame('cancelled', $cancelled->outcome);
        self::assertTrue($cancelled->ownershipLive);
        self::assertSame(SessionState::Cancelled, $sessions->findByUuid($session->getId())?->getSessionState());
        $released = $jobs->findByUuid($job->getId());
        self::assertNotNull($released);
        self::assertSame(0, $released->getReferenceCount());
        self::assertSame('in_progress', $released->getStatus()->value);

        // The worker reads the cancellation and gives up the execution; the next run reclaims the job.
        $latest = $runtime->refresh($claim);
        self::assertNotNull($latest);
        self::assertTrue($runtime->release($latest));
        $this->manager->clear();
        self::assertSame(['expired' => 0, 'cleaned' => 1], $handler(new \App\Transcode\Application\Command\CleanupOrphanedJobsCommand()));
        $this->manager->clear();
        self::assertNull($jobs->findByUuid($job->getId()));
    }

    /** @return array<string, mixed> */
    private function playbackRows(Uuid $jobId): array
    {
        $rows = $this->manager->getConnection()->fetchAssociative(
            'SELECT row_to_json(j)::text AS job, row_to_json(s)::text AS session, row_to_json(r)::text AS runtime'
            . ' FROM transcode_jobs j JOIN transcode_sessions s ON s.job_id = j.id'
            . ' JOIN transcode_runtime_state r ON r.job_id = j.id AND r.session_id = s.id WHERE j.id = ?',
            [$jobId->toString()],
        );
        self::assertNotFalse($rows);
        return $rows;
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
        $job = new TranscodeJobEntity(new PublicId(), $this->createVideo(), '1080p', ownerUserId: null);
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
