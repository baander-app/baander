<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceDispatchStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceMaterializer;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerRecoveryScheduleStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerWorkerAuthority;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Doctrine\Type\InstantType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Booting the application kernel installs the project instant type under Doctrine's names, and
 * every connection the application opens runs its session in UTC, whatever the client's default.
 *
 * The probe runs in a fresh process: the type registry is global, and this process has already
 * registered the project types.
 */
final class InstantRuntimeConfigurationTest extends TestCase
{
    /** @var array{before: array<string, string>, after: array<string, string>, timestamptz: string, zones: array<string, string>} */
    private static array $probe;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            return;
        }

        // libpq applies PGTZ to every new session; a connection that does not pin its zone keeps it.
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Shared/instant-runtime-probe.php'], null, ['PGTZ' => 'America/New_York']);
        $process->setTimeout(120);
        $process->mustRun();
        self::$probe = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires fully migrated disposable PostgreSQL.');
        }
    }

    public function testTheKernelRegistersTheInstantTypeUnderBothDoctrineNames(): void
    {
        self::assertSame(
            ['datetime_immutable' => DateTimeImmutableType::class, 'datetimetz_immutable' => DateTimeTzImmutableType::class],
            self::$probe['before'],
        );
        self::assertSame(
            ['datetime_immutable' => InstantType::class, 'datetimetz_immutable' => InstantType::class],
            self::$probe['after'],
        );
        self::assertSame('datetime_immutable', self::$probe['timestamptz']);
    }

    public function testEveryConnectionRunsItsSessionInUtc(): void
    {
        self::assertSame([
            'default' => 'UTC',
            DoctrineSchedulerManualOccurrenceRecorder::class => 'UTC',
            DoctrineSchedulerOccurrenceDispatchStore::class => 'UTC',
            DoctrineSchedulerOccurrenceExecutionStore::class => 'UTC',
            DoctrineSchedulerOccurrenceMaterializer::class => 'UTC',
            DoctrineSchedulerRecoveryScheduleStore::class => 'UTC',
            DoctrineSchedulerWorkerAuthority::class => 'UTC',
            DedicatedConnectionFactory::class => 'UTC',
        ], self::$probe['zones']);
    }
}
