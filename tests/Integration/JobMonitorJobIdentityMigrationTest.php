<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

/** Job monitors have unique job identities and valid finish times. */
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
