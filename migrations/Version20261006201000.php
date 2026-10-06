<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006201000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the library_file_index library_id index that the unique (library_id, path) index already covers.';
    }

    public function up(Schema $schema): void
    {
        // library_file_path_unique leads with library_id, so it serves library lookups and the
        // fk_library_file_index_library_id cascade.
        $this->addSql('DROP INDEX idx_library_file_index_library_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_library_file_index_library_id ON library_file_index (library_id)');
    }
}
