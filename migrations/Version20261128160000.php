<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve progress observation boundaries while allowing configured clock tolerance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE resume_positions ADD protected_through_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('UPDATE resume_positions SET protected_through_at = reported_at');
        $this->addSql('ALTER TABLE resume_positions ADD CONSTRAINT chk_resume_positions_protected_time CHECK (protected_through_at IS NULL OR protected_through_at <= reported_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing progress protection would discard observation boundaries.');
    }
}
