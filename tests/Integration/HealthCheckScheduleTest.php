<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007140000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The five-minute health check seeded by Version20261007140000, run through the scheduler's
 * occurrence path on the fully migrated disposable PostgreSQL inside one rolled-back transaction.
 */
final class HealthCheckScheduleTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testFreshInstallHasTheFiveMinuteHealthCheckJob(): void
    {
        self::loadMigration();
        self::assertSame([
            'name' => 'Check system health',
            'expression' => '*/5 * * * *',
            'job_type' => 'messenger',
            'command' => Version20261007140000::HEALTH_CHECK_COMMAND,
            'status' => 'active',
            'parameters' => '[]',
            'evaluated_through' => null,
            'next_run_is_on_a_five_minute_boundary' => true,
        ], $this->scheduledHealthCheck());

        $registry = $this->kernel->getContainer()->get('test.service_container')->get(SchedulerRegistry::class);
        self::assertInstanceOf(SchedulerRegistry::class, $registry);
        self::assertTrue($registry->isMessengerCommandAllowed(Version20261007140000::HEALTH_CHECK_COMMAND));
        self::assertSame([], $registry->getMessengerParameterSchema(Version20261007140000::HEALTH_CHECK_COMMAND));

        $this->runMigration('down');
        self::assertFalse($this->scheduledHealthCheck());
        $this->runMigration('up');
        self::assertIsArray($this->scheduledHealthCheck());
        self::assertTrue((bool) $this->manager->getConnection()->fetchOne(
            "SELECT next_run_at BETWEEN clock_timestamp() - INTERVAL '1 minute' AND clock_timestamp() + INTERVAL '5 minutes' FROM scheduled_jobs WHERE id = ?",
            [Version20261007140000::HEALTH_CHECK_JOB_ID],
        ));
    }

    public function testScheduledOccurrenceAlertsAdminsOfADegradationWhileAdminAlertsAreOn(): void
    {
        $admin = $this->createAdmin();
        $this->setAdminAlerts(true);
        $this->degradeRedis();

        $this->runScheduledOccurrence();

        self::assertSame(['redis'], $this->healthAlertComponents($admin));
        self::assertSame('dispatched', $this->lastResult());
    }

    public function testScheduledOccurrenceSendsNoAlertWhileAdminAlertsAreOff(): void
    {
        $admin = $this->createAdmin();
        $this->setAdminAlerts(false);
        $this->degradeRedis();

        $this->runScheduledOccurrence();

        self::assertSame([], $this->healthAlertComponents($admin));
        self::assertSame('dispatched', $this->lastResult());
    }

    private function createAdmin(): Uuid
    {
        $users = $this->kernel->getContainer()->get('test.service_container')->get(UserRepositoryInterface::class);
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $admin = User::createByOperator(
            new Email('health-admin-' . bin2hex(random_bytes(4)) . '@baander.app'),
            'unused-password',
            'Health admin',
            ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
        );
        $users->save($admin);

        return $admin->getId();
    }

    private function setAdminAlerts(bool $on): void
    {
        $store = $this->kernel->getContainer()->get('test.service_container')->get(SystemSettingStoreInterface::class);
        self::assertInstanceOf(SystemSettingStoreInterface::class, $store);
        $store->save([SharedSettingDefinitions::ADMIN_ALERTS => $on]);
    }

    /**
     * Redis was healthy at the previous check and refuses connections at the scheduled one.
     * No connection is attempted: the factory fails before reaching the network.
     */
    private function degradeRedis(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $container->set(RedisClientFactory::class, new RedisClientFactory(
            'redis://redis.baander.app:6379',
            connectionFactory: static fn (): \Redis => throw new \RedisException('Connection refused'),
        ));
        $alerts = $container->get(HealthAlertService::class);
        self::assertInstanceOf(HealthAlertService::class, $alerts);
        $alerts->evaluateAndAlert([new HealthCheckResult('redis', HealthStatus::Healthy, 1.0)]);
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

        $job = $jobs->getById(Uuid::fromString(Version20261007140000::HEALTH_CHECK_JOB_ID));
        self::assertNotNull($job);
        $handler->executeOccurrence(new SchedulerOccurrence(
            Uuid::generate(),
            $job->getId(),
            new \DateTimeImmutable('2026-10-08T04:05:00Z'),
            JobType::Messenger,
            $job->getCommand(),
            $job->getParameters(),
        ));
    }

    /** @return list<string> the component of each health alert the admin received */
    private function healthAlertComponents(Uuid $admin): array
    {
        return array_map(
            static fn (mixed $component): string => (string) $component,
            $this->manager->getConnection()->fetchFirstColumn(
                "SELECT reference_data->>'component' FROM notifications WHERE user_id = ? AND event_type = 'admin.health_degraded' ORDER BY reference_data->>'component'",
                [$admin->toString()],
            ),
        );
    }

    private function lastResult(): ?string
    {
        self::loadMigration();
        $result = $this->manager->getConnection()->fetchOne(
            'SELECT last_result FROM scheduled_jobs WHERE id = ?',
            [Version20261007140000::HEALTH_CHECK_JOB_ID],
        );

        return $result === null ? null : (string) $result;
    }

    /** @return array<string, mixed>|false */
    private function scheduledHealthCheck(): array|false
    {
        return $this->manager->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT name, expression, job_type, command, status, parameters::text AS parameters, evaluated_through,
                       to_char(next_run_at AT TIME ZONE 'UTC', 'SS.US') = '00.000000'
                           AND EXTRACT(MINUTE FROM next_run_at AT TIME ZONE 'UTC')::integer % 5 = 0 AS next_run_is_on_a_five_minute_boundary
                FROM scheduled_jobs WHERE id = ?
                SQL,
            [Version20261007140000::HEALTH_CHECK_JOB_ID],
        );
    }

    /** Migration classes are not autoloaded. */
    private static function loadMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007140000.php';
    }

    private function runMigration(string $direction): void
    {
        self::loadMigration();
        $connection = $this->manager->getConnection();
        $migration = new Version20261007140000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
