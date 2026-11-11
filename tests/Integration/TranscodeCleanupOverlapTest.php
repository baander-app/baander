<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\StaleTranscodeSessionExpiry;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use App\Transcode\Infrastructure\Transcode\TranscodeSessionService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Two cleanup runs, or a cleanup and another writer, overlap on committed rows. */
final class TranscodeCleanupOverlapTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    /** @return iterable<string, array{string}> */
    public static function whatTheOtherProcessDeletes(): iterable
    {
        yield 'the session row of a job this run is about to remove' => ['DELETE FROM transcode_sessions WHERE job_id = ?'];
        yield 'the job this run is about to remove, and its session with it' => ['DELETE FROM transcode_jobs WHERE id = ?'];
    }

    #[DataProvider('whatTheOtherProcessDeletes')]
    public function testARowAnotherProcessDeletedDuringTheRunDoesNotFailTheJobsAfterIt(string $otherProcess): void
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
        // The harness's own job has no session; this run starts from the three below.
        $this->first->executeStatement('DELETE FROM transcode_jobs WHERE id = ?', [$this->jobId->toString()]);
        $jobOf = [];
        foreach (['360p', '720p', '1080p'] as $tier) {
            $job = TranscodeJob::create($this->videoId, QualityTier::fromString($tier), '/fixture/abandoned/' . $tier, $this->userId, requestId: 'abandoned-player');
            $job->attachSession();
            $job->markInProgress();
            $jobs->save($job);
            $session = TranscodeSession::create($this->userId, $job->getId(), $this->videoId, AudioProfile::streamingStereo());
            $session->markPreparing();
            $session->markActive();
            $sessions->save($session);
            $this->first->transactional(static fn () => $runtime->admit($job->getId(), $session->getId(), 'abandoned-player', new DesiredTranscodeState(AudioProfile::streamingStereo(), 'running', 12.0)));
            $jobOf[$job->getOutputDirectory()] = $job->getId()->toString();
        }
        $this->first->executeStatement("UPDATE transcode_sessions SET updated_at = clock_timestamp() - interval '2 days'");
        $manager->clear();
        // The other process commits on its own connection after this run has expired the sessions,
        // which it still manages, and before it removes the first job it reaches.
        $deleted = null;
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('deleteIfUnretained')->willReturnCallback(function (string $directory) use (&$deleted, $jobOf, $otherProcess): bool {
            if ($deleted === null) {
                $deleted = $jobOf[$directory];
                self::assertSame(1, $this->second->executeStatement($otherProcess, [$deleted]));
            }

            return true;
        });
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $level . ': ' . $message . (isset($context['exception']) && $context['exception'] instanceof \Throwable ? ' (' . $context['exception']->getMessage() . ')' : '');
            }
        };
        $jobService = new TranscodeJobService($jobs, $storage, new EventDispatcher(), $runtime, $transaction, $logger);
        $handler = new CleanupOrphanedJobsHandler(
            $jobService,
            new StaleTranscodeSessionExpiry(new TranscodeSessionService($sessions), $runtime, $jobService, $transaction, new NativeClock(), $logger),
            $logger,
        );

        $result = $handler(new CleanupOrphanedJobsCommand());

        self::assertNotNull($deleted, 'The other process ran.');
        self::assertSame([], $logger->records);
        self::assertSame(['expired' => 3, 'cleaned' => 3], $result->toArray());
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_jobs'));
        self::assertSame(0, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_sessions'));
    }
}
