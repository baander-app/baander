<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require a job for every transcode session again, after removing sessions without one.';
    }

    public function up(Schema $schema): void
    {
        // Version20260716235039 dropped NOT NULL only to match a nullable mapping. No code path
        // writes a session without a job, and such a row cannot be loaded into the aggregate.
        $this->addSql('DELETE FROM transcode_sessions WHERE job_id IS NULL');
        $this->addSql('ALTER TABLE transcode_sessions ALTER job_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // The deleted sessions are not restored.
        $this->addSql('ALTER TABLE transcode_sessions ALTER job_id DROP NOT NULL');
    }
}
