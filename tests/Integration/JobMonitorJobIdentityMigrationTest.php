<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006330000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Version20261006330000 merges the job_monitors rows that retries duplicated, then makes
 * job_id unique and forbids a finish before the start.
 */
final class JobMonitorJobIdentityMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    protected function setUp(): void
    {
        $this->setUpHarness();
        // Literal instants below are UTC, and so is their text form in the assertions.
        $this->manager->getConnection()->executeStatement("SET LOCAL TIME ZONE 'UTC'");
    }

    public function testSchemaComparisonIsCleanForJobMonitors(): void
    {
        $this->assertSchemaComparisonIsClean(['job_monitors']);
    }

    public function testUpgradeMergesDuplicatedJobsIntoTheirNewestRow(): void
    {
        $connection = $this->manager->getConnection();
        $this->migrate('down');

        // retried-job: received from async, failed, and retried from the failure transport, which
        // is still running. Status updates by job_id rewrote both rows alike. A manual retry
        // marked the older row. Its newest row keeps its id.
        $this->insert('00000000-0000-7000-8000-000000000001', 'retried-job', 'async', 'running', '2026-10-01 10:00:00', '2026-10-01 10:05:00', '2026-10-01 10:01:00', retried: true, auditLog: '[{"action":"retry"}]');
        $this->insert('00000000-0000-7000-8000-000000000002', 'retried-job', 'failed', 'running', '2026-10-01 10:02:00', '2026-10-01 10:05:00', '2026-10-01 10:01:00');
        // finished-job: three deliveries; the last one finished after its start.
        foreach (['3', '4', '5'] as $minute) {
            $this->insert('00000000-0000-7000-8000-00000000000' . $minute, 'finished-job', 'async', 'finished', '2026-10-01 11:0' . $minute . ':00', '2026-10-01 11:06:00', '2026-10-01 11:07:00');
        }
        $this->insert('00000000-0000-7000-8000-000000000006', 'single-job', 'swoole_task', 'finished', '2026-10-01 12:00:00', '2026-10-01 12:00:00', '2026-10-01 12:00:30');
        $this->insert('00000000-0000-7000-8000-000000000007', 'queued-job', 'async', 'queued', '2026-10-01 13:00:00', null, null);

        $this->migrate('up');

        $rows = $connection->fetchAllAssociativeIndexed(
            "SELECT job_id, id, queue, status, created_at, queued_at, started_at, finished_at, duration_microseconds,
                    attempt, retried, audit_log
             FROM job_monitors WHERE job_id LIKE '%-job' ORDER BY job_id",
        );
        self::assertSame(['finished-job', 'queued-job', 'retried-job', 'single-job'], array_keys($rows));
        self::assertSame([
            'id' => '00000000-0000-7000-8000-000000000002',
            'queue' => 'async',
            'status' => 'running',
            'created_at' => '2026-10-01 10:00:00+00',
            'queued_at' => '2026-10-01 10:00:00+00',
            'started_at' => '2026-10-01 10:05:00+00',
            'finished_at' => null,
            'duration_microseconds' => null,
            'attempt' => 2,
            'retried' => true,
            'audit_log' => '[{"action":"retry"}]',
        ], $rows['retried-job']);
        self::assertSame(['00000000-0000-7000-8000-000000000005', 'finished', '2026-10-01 11:03:00+00', 60_000_000, 3, false], [
            $rows['finished-job']['id'], $rows['finished-job']['status'], $rows['finished-job']['created_at'],
            $rows['finished-job']['duration_microseconds'], $rows['finished-job']['attempt'], $rows['finished-job']['retried'],
        ]);
        self::assertSame([30_000_000, 1], [$rows['single-job']['duration_microseconds'], $rows['single-job']['attempt']]);
        self::assertSame([null, 0], [$rows['queued-job']['started_at'], $rows['queued-job']['attempt']]);
    }

    public function testUpgradeClampsAFinishedRowThatFinishedBeforeItStarted(): void
    {
        $this->migrate('down');
        $this->insert('00000000-0000-7000-8000-000000000008', 'skewed-job', 'async', 'failed', '2026-10-01 14:00:00', '2026-10-01 14:00:10', '2026-10-01 14:00:05');

        $this->migrate('up');

        self::assertSame(
            ['finished_at' => '2026-10-01 14:00:10+00', 'duration_microseconds' => 0],
            $this->manager->getConnection()->fetchAssociative("SELECT finished_at, duration_microseconds FROM job_monitors WHERE job_id = 'skewed-job'"),
        );
    }

    public function testTheCatalogRejectsADuplicateJobAndAFinishBeforeTheStart(): void
    {
        $connection = $this->manager->getConnection();
        self::assertSame([
            'chk_job_monitors_finished_at' => 'CHECK ((finished_at >= started_at))',
            'chk_job_monitors_status' => "CHECK ((status = ANY (ARRAY['queued'::text, 'running'::text, 'finished'::text, 'failed'::text, 'cancelled'::text])))",
            'job_monitors_pkey' => 'PRIMARY KEY (id)',
            'uniq_job_monitors_job_id' => 'UNIQUE (job_id)',
        ], $connection->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'job_monitors'::regclass AND contype <> 'n' ORDER BY conname",
        ));
        self::assertFalse($connection->fetchOne("SELECT to_regclass('idx_job_monitors_job_id') IS NOT NULL"));

        $this->insert('00000000-0000-7000-8000-000000000009', 'unique-job', 'async', 'running', '2026-10-01 15:00:00', '2026-10-01 15:00:00', null);
        $violations = [];
        foreach ([
            fn () => $this->insert('00000000-0000-7000-8000-00000000000a', 'unique-job', 'async', 'queued', '2026-10-01 15:01:00', null, null),
            fn () => $connection->executeStatement("UPDATE job_monitors SET finished_at = started_at - INTERVAL '1 second' WHERE job_id = 'unique-job'"),
        ] as $write) {
            $connection->executeStatement('SAVEPOINT job_identity_violation');
            try {
                $write();
                self::fail('The write must violate a constraint.');
            } catch (DriverException $exception) {
                $violations[] = $exception->getSQLState();
            } finally {
                $connection->executeStatement('ROLLBACK TO SAVEPOINT job_identity_violation');
            }
        }
        self::assertSame(['23505', '23514'], $violations);
    }

    private function migrate(string $direction): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261006330000.php';
        $migration = new Version20261006330000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insert(
        string $id,
        string $jobId,
        string $queue,
        string $status,
        string $createdAt,
        ?string $startedAt,
        ?string $finishedAt,
        bool $retried = false,
        ?string $auditLog = null,
    ): void {
        $this->manager->getConnection()->executeStatement(
            'INSERT INTO job_monitors (id, job_id, name, queue, status, queued_at, started_at, finished_at, retried, audit_log, created_at, updated_at)
             VALUES (:id, :job_id, :name, :queue, :status, :created_at, :started_at, :finished_at, :retried, :audit_log, :created_at, :created_at)',
            [
                'id' => $id, 'job_id' => $jobId, 'name' => 'ExtractAlbumCoverCommand', 'queue' => $queue, 'status' => $status,
                'created_at' => $createdAt, 'started_at' => $startedAt, 'finished_at' => $finishedAt,
                'retried' => $retried, 'audit_log' => $auditLog,
            ],
            ['retried' => ParameterType::BOOLEAN],
        );
    }
}
