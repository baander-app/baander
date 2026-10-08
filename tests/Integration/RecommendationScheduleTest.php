<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Recommendation\Application\CommandHandler\GenerateRecommendationsHandler;
use App\Recommendation\Application\Settings\RecommendationSettingDefinitions;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007130000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The daily recommendation generation seeded by Version20261007130000, run through the
 * scheduler's occurrence path on the fully migrated disposable PostgreSQL inside one
 * rolled-back transaction.
 */
final class RecommendationScheduleTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testFreshInstallHasTheDailyGenerationJob(): void
    {
        self::loadMigration();
        self::assertSame([
            'name' => 'Generate recommendations',
            'expression' => '0 4 * * *',
            'job_type' => 'messenger',
            'command' => Version20261007130000::GENERATION_COMMAND,
            'status' => 'active',
            'parameters' => '{"mode":"incremental","automatic":true}',
            'evaluated_through' => null,
            'next_run_is_a_0400_utc' => true,
        ], $this->scheduledGeneration());

        $registry = $this->kernel->getContainer()->get('test.service_container')->get(SchedulerRegistry::class);
        self::assertInstanceOf(SchedulerRegistry::class, $registry);
        self::assertTrue($registry->isMessengerCommandAllowed(Version20261007130000::GENERATION_COMMAND));
        self::assertSame(
            ['mode' => 'string', 'automatic' => 'bool'],
            array_map(
                static fn (array $definition): string => $definition['type'],
                $registry->getMessengerParameterSchema(Version20261007130000::GENERATION_COMMAND),
            ),
        );

        $this->runMigration('down');
        self::assertFalse($this->scheduledGeneration());
        $this->runMigration('up');
        self::assertIsArray($this->scheduledGeneration());
    }

    public function testScheduledOccurrenceGeneratesWhileTheToggleIsOn(): void
    {
        $songs = $this->createSongs();
        $this->setAutoGenerate(true);

        $this->runScheduledOccurrence();

        self::assertGreaterThan(0, $this->recommendationsFrom($songs));
        self::assertSame('dispatched', $this->lastResult());
    }

    public function testScheduledOccurrenceSkipsWhileTheToggleIsOffAndTheCliStillGenerates(): void
    {
        $songs = $this->createSongs();
        $this->setAutoGenerate(false);

        $this->runScheduledOccurrence();

        self::assertSame(0, $this->recommendationsFrom($songs));
        self::assertSame(GenerateRecommendationsHandler::SKIPPED_AUTO_GENERATE_OFF, $this->lastResult());

        $console = new Application($this->kernel);
        $tester = new CommandTester($console->find('app:recommendation:generate'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertGreaterThan(0, $this->recommendationsFrom($songs));
    }

    /** @return list<string> IDs of two songs whose default audio features make them similar */
    private function createSongs(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $library = new LibraryEntity('Scheduled recommendations', 'scheduled-recommendations-' . $suffix, '/scheduled-recommendations-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Daily', 'album');
        $first = new SongEntity(new PublicId(), $album, 'Morning', '/scheduled-recommendations-' . $suffix . '/morning.flac', 1, 'audio/flac');
        $second = new SongEntity(new PublicId(), $album, 'Evening', '/scheduled-recommendations-' . $suffix . '/evening.flac', 1, 'audio/flac');
        foreach ([$library, $album, $first, $second] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->manager->clear();

        return [$first->getId()->toString(), $second->getId()->toString()];
    }

    private function setAutoGenerate(bool $on): void
    {
        $store = $this->kernel->getContainer()->get('test.service_container')->get(SystemSettingStoreInterface::class);
        self::assertInstanceOf(SystemSettingStoreInterface::class, $store);
        $store->save([RecommendationSettingDefinitions::AUTO_GENERATE => $on]);
    }

    /** The scheduler worker's path for one due minute of the seeded job. */
    private function runScheduledOccurrence(): void
    {
        self::loadMigration();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $jobs = $container->get(ScheduledJobPortInterface::class);
        $handler = $container->get(ExecuteScheduledJobHandler::class);
        self::assertInstanceOf(ScheduledJobPortInterface::class, $jobs);
        self::assertInstanceOf(ExecuteScheduledJobHandler::class, $handler);

        $job = $jobs->getById(Uuid::fromString(Version20261007130000::GENERATION_JOB_ID));
        self::assertNotNull($job);
        $handler->executeOccurrence(new SchedulerOccurrence(
            Uuid::generate(),
            $job->getId(),
            new \DateTimeImmutable('2026-10-08T04:00:00Z'),
            JobType::Messenger,
            $job->getCommand(),
            $job->getParameters(),
        ));
    }

    /** @param list<string> $songIds */
    private function recommendationsFrom(array $songIds): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            "SELECT count(*) FROM recommendations WHERE source_type = 'song' AND source_id IN (?)",
            [$songIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function lastResult(): ?string
    {
        self::loadMigration();
        $result = $this->manager->getConnection()->fetchOne(
            'SELECT last_result FROM scheduled_jobs WHERE id = ?',
            [Version20261007130000::GENERATION_JOB_ID],
        );

        return $result === null ? null : (string) $result;
    }

    /** @return array<string, mixed>|false */
    private function scheduledGeneration(): array|false
    {
        return $this->manager->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT name, expression, job_type, command, status, parameters::text AS parameters, evaluated_through,
                       next_run_at > clock_timestamp() - INTERVAL '1 day'
                           AND to_char(next_run_at AT TIME ZONE 'UTC', 'HH24:MI:SS') = '04:00:00' AS next_run_is_a_0400_utc
                FROM scheduled_jobs WHERE id = ?
                SQL,
            [Version20261007130000::GENERATION_JOB_ID],
        );
    }

    /** Migration classes are not autoloaded. */
    private static function loadMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007130000.php';
    }

    private function runMigration(string $direction): void
    {
        self::loadMigration();
        $connection = $this->manager->getConnection();
        $migration = new Version20261007130000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
