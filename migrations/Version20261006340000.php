<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006340000 extends AbstractMigration
{
    private const array INSTANTS = ['queued_at', 'started_at', 'finished_at', 'created_at', 'updated_at'];

    public function getDescription(): string
    {
        return 'Store job_monitors instants as timestamptz without rounding them to whole seconds.';
    }

    public function up(Schema $schema): void
    {
        // TIMESTAMP(0) rounded every instant to the nearest second, so a run of 0.2 s could be
        // stored as 0 s or 1 s. PostgreSQL refuses to change the type of a column that a
        // generated column reads, so duration_microseconds is dropped and added again with the
        // same expression. Relaxing the precision keeps the stored values and needs no rewrite;
        // adding the stored column rewrites the table under the ACCESS EXCLUSIVE lock that the
        // first statement takes and the transactional migration holds until it commits.
        // chk_job_monitors_finished_at and the created_at indexes carry over unchanged.
        $this->addSql('ALTER TABLE job_monitors DROP COLUMN duration_microseconds');
        $this->addSql('ALTER TABLE job_monitors ' . implode(', ', array_map(
            static fn (string $column): string => sprintf('ALTER COLUMN %s TYPE TIMESTAMPTZ', $column),
            self::INSTANTS,
        )));
        $this->addDuration();
    }

    public function down(Schema $schema): void
    {
        // Rounding loses the fractions for good. It never orders a finish before its start,
        // because rounding both times to the nearest second keeps their order.
        $this->addSql('ALTER TABLE job_monitors DROP COLUMN duration_microseconds');
        $this->addSql('ALTER TABLE job_monitors ' . implode(', ', array_map(
            static fn (string $column): string => sprintf('ALTER COLUMN %s TYPE TIMESTAMP(0) WITH TIME ZONE', $column),
            self::INSTANTS,
        )));
        $this->addDuration();
    }

    private function addDuration(): void
    {
        $this->addSql('ALTER TABLE job_monitors ADD COLUMN duration_microseconds BIGINT
            GENERATED ALWAYS AS ((EXTRACT(EPOCH FROM finished_at - started_at) * 1000000)::bigint) STORED');
    }
}
