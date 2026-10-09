<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen library_file_index.size and modified_at to BIGINT: file sizes pass 2 GiB, and modification times in epoch seconds pass January 2038.';
    }

    public function up(Schema $schema): void
    {
        // Widening rewrites the table under an exclusive lock; the index holds one row per media file.
        $this->addSql('ALTER TABLE library_file_index ALTER COLUMN size TYPE BIGINT, ALTER COLUMN modified_at TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // Fails with "integer out of range" rather than truncate a value that no longer fits.
        $this->addSql('ALTER TABLE library_file_index ALTER COLUMN size TYPE INT, ALTER COLUMN modified_at TYPE INT');
    }
}
