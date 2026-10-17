<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/** Job monitor instants retain microseconds, generated duration and the finish check. */
final class JobMonitorInstantPrecisionMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    private const array INSTANTS = ['created_at', 'finished_at', 'queued_at', 'started_at', 'updated_at'];

    protected function setUp(): void
    {
        $this->setUpHarness();
        // Literal instants below are UTC, and so is their text form in the assertions.
        $this->manager->getConnection()->executeStatement("SET LOCAL TIME ZONE 'UTC'");
    }

    public function testTheCatalogKeepsMicrosecondsAndTheDerivedColumns(): void
    {
        $connection = $this->manager->getConnection();
        self::assertSame(
            array_fill_keys(self::INSTANTS, 'timestamp with time zone') + ['duration_microseconds' => 'bigint'],
            $connection->fetchAllKeyValue(
                "SELECT attname, format_type(atttypid, atttypmod) FROM pg_attribute
                 WHERE attrelid = 'job_monitors'::regclass AND attname IN ('created_at', 'finished_at', 'queued_at', 'started_at', 'updated_at', 'duration_microseconds')
                 ORDER BY attname = 'duration_microseconds', attname",
            ),
        );
        self::assertSame(
            '((EXTRACT(epoch FROM (finished_at - started_at)) * (1000000)::numeric))::bigint',
            $connection->fetchOne(
                "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
                 JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
                 WHERE d.adrelid = 'job_monitors'::regclass AND a.attname = 'duration_microseconds' AND a.attgenerated = 's'",
            ),
        );
        self::assertSame('CHECK ((finished_at >= started_at))', $connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'job_monitors'::regclass AND conname = 'chk_job_monitors_finished_at'",
        ));

        // A run of 0.2 s: TIMESTAMP(0) stored it as 10:00:00 to 10:00:01, a whole second.
        $this->insert('sub-second-job', '2026-10-01 10:00:00.4', '2026-10-01 10:00:00.6');
        self::assertSame(
            ['started_at' => '2026-10-01 10:00:00.4+00', 'finished_at' => '2026-10-01 10:00:00.6+00', 'duration_microseconds' => 200_000],
            $connection->fetchAssociative("SELECT started_at, finished_at, duration_microseconds FROM job_monitors WHERE job_id = 'sub-second-job'"),
        );

        $connection->executeStatement('SAVEPOINT finish_before_start');
        try {
            $connection->executeStatement("UPDATE job_monitors SET finished_at = started_at - INTERVAL '1 microsecond' WHERE job_id = 'sub-second-job'");
            self::fail('A finish one microsecond before the start must violate chk_job_monitors_finished_at.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        } finally {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT finish_before_start');
        }
    }

    private function insert(string $jobId, ?string $startedAt, ?string $finishedAt): void
    {
        $this->manager->getConnection()->executeStatement(
            "INSERT INTO job_monitors (id, job_id, name, queue, status, queued_at, started_at, finished_at, created_at, updated_at)
             VALUES (gen_random_uuid(), :job_id, 'ExtractAlbumCoverCommand', 'async', :status, :created_at, :started_at, :finished_at, :created_at, :created_at)",
            [
                'job_id' => $jobId,
                'status' => $finishedAt === null ? 'queued' : 'finished',
                'created_at' => '2026-10-01 09:00:00',
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ],
        );
    }
}
