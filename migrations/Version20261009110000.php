<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen songs.size to BIGINT: lossless and uncompressed audio files can pass 2 GiB.';
    }

    public function up(Schema $schema): void
    {
        // Widening rewrites the table under an exclusive lock; no index, view or generated column reads size.
        $this->addSql('ALTER TABLE songs ALTER COLUMN size TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // Fails with "integer out of range" rather than truncate a size that no longer fits.
        $this->addSql('ALTER TABLE songs ALTER COLUMN size TYPE INT');
    }
}
