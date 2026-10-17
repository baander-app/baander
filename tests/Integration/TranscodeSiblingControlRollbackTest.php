<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Transcode\Application\Command\CancelTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CancelTranscodeSessionHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use App\Transcode\Infrastructure\Transcode\TranscodeSessionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class TranscodeSiblingControlRollbackTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    private bool $injectFailure = true;

    /** @return iterable<string, array{int, bool}> */
    public static function failureBoundaries(): iterable
    {
        yield 'second tier projection' => [2, false];
        yield 'third tier projection' => [3, false];
        yield 'second tier after source encoding completed' => [2, true];
        yield 'third tier after source encoding completed' => [3, true];
    }

    #[DataProvider('failureBoundaries')]
    public function testLaterTierFailureRollsBackEveryRuntimeProjectionReferenceAndReceipt(int $failOnSave, bool $sourceEncodingComplete): void
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
        $jobs = new TranscodeJobRepository($manager);
        $sessions = new TranscodeSessionRepository($manager);
        $sessionService = new TranscodeSessionService($sessions);
        $jobService = new TranscodeJobService($jobs, $this->createStub(TranscodeStoragePortInterface::class), new EventDispatcher());
        $tierSessions = [];
        foreach ([QualityTier::p1080(), QualityTier::p360(), QualityTier::p720()] as $index => $tier) {
            $job = $index === 0
                ? $jobs->findByUuid($this->jobId)
                : TranscodeJob::create($this->videoId, $tier, '/fixture/' . $tier->name, $this->userId);
            self::assertNotNull($job);
            $job->attachSession();
            $jobs->save($job);
            $session = TranscodeSession::create($this->userId, $job->getId(), $this->videoId, AudioProfile::streamingStereo());
            $session->markPreparing();
            $session->markActive();
            $transaction->run(function () use ($session, $sessions, $runtime, $tier): void {
                $sessions->save($session);
                $runtime->admit($session->getJobId(), $session->getId(), 'admit-' . $tier->name, new DesiredTranscodeState(AudioProfile::streamingStereo()));
            });
            $tierSessions[] = $session;
        }
        if ($sourceEncodingComplete) {
            $claim = $runtime->claim($this->jobId, $this->worker());
            self::assertNotNull($claim);
            self::assertTrue($runtime->observe($claim, 'complete', '/fixture/generation-' . $claim->runtime->generation));
            $completedJob = $jobs->findByUuid($this->jobId);
            self::assertNotNull($completedJob);
            $completedJob->markInProgress();
            $completedJob->markCompleted();
            $jobs->save($completedJob);
        }
        $manager->clear();
        $before = $this->snapshot($this->second);
        $failure = new \RuntimeException('A later tier failed after its real ORM flush.');
        $saveCount = 0;
        $port = $this->createStub(TranscodeSessionPortInterface::class);
        $port->method('findByUuid')->willReturnCallback($sessionService->findByUuid(...));
        $port->method('findActiveByUserAndVideo')->willReturnCallback($sessionService->findActiveByUserAndVideo(...));
        $port->method('save')->willReturnCallback(function (TranscodeSession $session) use ($sessionService, $tierSessions, $failOnSave, $failure, &$saveCount, $before): void {
            $sessionService->save($session);
            ++$saveCount;
            if (!$this->injectFailure) {
                return;
            }
            // Before the first projection can escape, the whole group and its video are locked.
            if ($saveCount === 1) {
                $this->assertRowIsLocked('videos', 'id', $this->videoId->toString());
                foreach ($tierSessions as $tierSession) {
                    $this->assertRowIsLocked('transcode_runtime_state', 'job_id', $tierSession->getJobId()->toString());
                }
            }
            if ($saveCount === $failOnSave) {
                self::assertSame($failOnSave, (int) $this->first->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE outcome = 'cancelled'"));
                self::assertSame($failOnSave, (int) $this->first->fetchOne("SELECT count(*) FROM transcode_sessions WHERE state = 'cancelled'"));
                self::assertSame($failOnSave - 1, (int) $this->first->fetchOne('SELECT count(*) FROM transcode_jobs WHERE reference_count = 0'));
                self::assertSame($before, $this->snapshot($this->second), 'An independent reader must still see the original committed group.');
                throw $failure;
            }
        });
        $handler = new CancelTranscodeSessionHandler(new TranscodePlaybackControl($port, $runtime, $transaction, $jobService));
        $command = new CancelTranscodeSessionCommand($tierSessions[0]->getId(), 'cancel-group');
        try {
            $handler($command);
            self::fail('The later sibling failure must propagate out of the transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        self::assertSame($failOnSave, $saveCount);
        self::assertFalse($this->first->isTransactionActive());
        self::assertSame(0, $manager->getUnitOfWork()->size(), 'Rollback must discard projections of aborted writes.');
        self::assertSame($before, $this->snapshot($this->second));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_control_receipts WHERE request_id = ?', ['cancel-group']));

        // The exact same command can commit after the fault; no aborted receipt suppresses a tier.
        $this->injectFailure = false;
        $handler($command);
        self::assertSame(3, (int) $this->second->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE outcome = 'cancelled'"));
        self::assertSame(3, (int) $this->second->fetchOne("SELECT count(*) FROM transcode_sessions WHERE state = 'cancelled'"));
        self::assertSame(3, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_jobs WHERE reference_count = 0'));
        self::assertSame(3, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_control_receipts WHERE request_id = ?', ['cancel-group']));
    }

    private function assertRowIsLocked(string $table, string $key, string $id): void
    {
        try {
            $this->second->executeQuery('SELECT ' . $key . ' FROM ' . $table . ' WHERE ' . $key . ' = ? FOR UPDATE NOWAIT', [$id])->free();
            self::fail('A participating row was not locked before sibling mutation: ' . $table);
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(Connection $connection): array
    {
        $snapshot = [];
        foreach (['transcode_jobs' => 'id', 'transcode_sessions' => 'id', 'transcode_runtime_state' => 'job_id', 'transcode_control_receipts' => 'session_id, request_id'] as $table => $order) {
            $snapshot[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
        }
        return $snapshot;
    }
}
