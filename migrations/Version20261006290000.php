<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006290000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the generated job_monitors.duration_microseconds column for sorting jobs by run time.';
    }

    public function up(Schema $schema): void
    {
        // A stored generated column rewrites job_monitors under an ACCESS EXCLUSIVE lock; the
        // table holds pruned monitoring rows only. EXTRACT(EPOCH FROM interval) is an exact
        // numeric, so the column is NULL without both times and otherwise loses no precision.
        $this->addSql('ALTER TABLE job_monitors ADD COLUMN duration_microseconds BIGINT
            GENERATED ALWAYS AS ((EXTRACT(EPOCH FROM finished_at - started_at) * 1000000)::bigint) STORED');
    }

    public function down(Schema $schema): void
    {
        // The column is derived from started_at and finished_at, so dropping it loses nothing.
        $this->addSql('ALTER TABLE job_monitors DROP COLUMN duration_microseconds');
    }
}
