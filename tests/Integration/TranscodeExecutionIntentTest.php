<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

final class TranscodeExecutionIntentTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    public function testSessionAndExecutionIntentBecomeVisibleInOneCommit(): void
    {
        $this->first->beginTransaction();
        $this->runtime->lockAdmission($this->videoId);
        $this->insertSession();
        $this->runtime->admit($this->jobId, $this->sessionId, 'start', new DesiredTranscodeState(AudioProfile::streamingStereo()));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
        self::assertNull($this->runtimeFor($this->second)->find($this->jobId));
        $this->first->commit();
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
        $other = $this->runtimeFor($this->second);
        self::assertSame('pending', $other->find($this->jobId)?->outcome);
        self::assertCount(1, $other->pending(), 'Committed work is discoverable without a notification.');
        self::assertNotNull($other->claim($this->jobId, $this->worker()));
    }

    public function testFailureAfterIntentInsertionRollsBackTheSessionAndIntent(): void
    {
        $failure = new \RuntimeException('Outbox capture failed');
        try {
            $this->first->transactional(function () use ($failure): void {
                $this->insertSession();
                $this->runtime->admit($this->jobId, $this->sessionId, 'start', new DesiredTranscodeState(AudioProfile::streamingStereo()));
                throw $failure;
            });
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
        self::assertNull($this->runtimeFor($this->second)->find($this->jobId));
    }

    public function testRepeatedAdmissionConvergesAndRequiresAnOuterTransaction(): void
    {
        $first = $this->admit();
        $again = $this->first->transactional(fn () => $this->runtime->admit($this->jobId, $this->sessionId, 'start', new DesiredTranscodeState(AudioProfile::mobileMono())));
        self::assertSame($first->generation, $again->generation);
        self::assertSame('streaming_stereo', $again->desired->audioProfile->name);
        $this->expectException(\LogicException::class);
        $this->runtime->admit($this->jobId, $this->sessionId, 'start', new DesiredTranscodeState(AudioProfile::streamingStereo()));
    }

    public function testStartupRefusalPreventsLateAcceptanceAndReleasesTheClaim(): void
    {
        $this->admit();
        $this->first->executeStatement('UPDATE transcode_jobs SET reference_count = 1');
        $claim = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($claim);
        self::assertTrue($this->runtime->expireAdmission($this->jobId, $this->sessionId, 'start'));
        self::assertFalse($this->runtime->observe($claim, 'accepted'));
        self::assertFalse($this->runtime->renew($claim));
        self::assertNull($this->first->fetchOne('SELECT claim_token FROM transcode_runtime_state'));
        self::assertSame('refused', $this->runtime->find($this->jobId)?->outcome);
        self::assertSame('failed', $this->second->fetchOne('SELECT state FROM transcode_sessions'));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT reference_count FROM transcode_jobs'));
        self::assertFalse($this->runtime->expireAdmission($this->jobId, $this->sessionId, 'start'));
    }

    /** @return iterable<string, array{string}> */
    public static function producerFailures(): iterable
    {
        yield 'session flush' => ['session'];
        yield 'intent insertion' => ['intent'];
        yield 'outbox capture' => ['outbox'];
    }

    #[DataProvider('producerFailures')]
    public function testProducerFailureRollsBackOrmFlushIntentAndOutboxAndClearsManagedState(string $boundary): void
    {
        CustomTypesRegistrar::register();
        $configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src'], isDevMode: true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->enableNativeLazyObjects(true);
        $manager = new EntityManager($this->first, $configuration);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->method('resetManager')->willReturn($manager);
        $transaction = new DoctrineTransaction($registry, new Swoole());
        $runtime = new TranscodeRuntimeState($this->first, $transaction);
        $session = TranscodeSession::create($boundary === 'session' ? Uuid::generate() : $this->userId, $this->jobId, $this->videoId, AudioProfile::streamingStereo());
        $failure = null;
        try {
            $transaction->run(function () use ($session, $manager, $runtime, $boundary): void {
                (new TranscodeSessionRepository($manager))->save($session);
                self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
                $runtime->admit($this->jobId, $boundary === 'intent' ? Uuid::generate() : $session->getId(), 'start', new DesiredTranscodeState(AudioProfile::streamingStereo()));
                // Exercise a real outbox capture failure after the aggregate flush and intent write.
                $this->first->executeStatement("INSERT INTO domain_event_outbox (event_class, event_name, payload, created_at) VALUES (NULL, 'media', '{}', clock_timestamp())");
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertSame(0, $manager->getUnitOfWork()->size());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_runtime_state'));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM domain_event_outbox'));
    }

    public function testRefusalDetachesOnlyItsSessionReference(): void
    {
        $this->admit();
        $this->insertSession(Uuid::generate());
        $this->insertSession(Uuid::generate());
        $this->first->executeStatement('UPDATE transcode_jobs SET reference_count = 3');
        self::assertTrue($this->runtime->expireAdmission($this->jobId, $this->sessionId, 'start'));
        self::assertSame(2, (int) $this->second->fetchOne('SELECT reference_count FROM transcode_jobs'));
        self::assertSame(2, (int) $this->second->fetchOne("SELECT count(*) FROM transcode_sessions WHERE state = 'pending'"));
        self::assertFalse($this->runtime->expireAdmission($this->jobId, $this->sessionId, 'start'));
        self::assertSame(2, (int) $this->second->fetchOne('SELECT reference_count FROM transcode_jobs'));
    }

    public function testForwardMigrationCreatesBothAuthoritiesWithTimezoneAwareInstants(): void
    {
        $tables = $this->first->createSchemaManager()->listTableNames();
        self::assertContains('transcode_runtime_state', $tables);
        self::assertContains('audio_rendition_runtime', $tables);
        self::assertSame(2, (int) $this->first->fetchOne("SELECT count(*) FROM pg_constraint c JOIN pg_class r ON r.oid = c.conrelid JOIN pg_namespace n ON n.oid = r.relnamespace WHERE n.nspname = ? AND r.relname = 'transcode_runtime_state' AND c.contype = 'f'", [$this->schema]));
        self::assertSame(0, (int) $this->first->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = ? AND table_name IN ('transcode_runtime_state', 'audio_rendition_runtime') AND column_name IN ('updated_at', 'lease_expires_at', 'admission_deadline') AND data_type <> 'timestamp with time zone'", [$this->schema]));
    }
}
