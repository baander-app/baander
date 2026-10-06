<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade library deletion to library_file_index after removing rows of already deleted libraries.';
    }

    public function up(Schema $schema): void
    {
        // Rows left behind by earlier library deletions would make the constraint fail validation.
        $this->addSql('DELETE FROM library_file_index f WHERE NOT EXISTS (SELECT 1 FROM libraries l WHERE l.id = f.library_id)');
        // idx_library_file_index_library_id serves the cascade lookup.
        $this->addSql('ALTER TABLE library_file_index ADD CONSTRAINT fk_library_file_index_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // The deleted orphan rows are not restored.
        $this->addSql('ALTER TABLE library_file_index DROP CONSTRAINT fk_library_file_index_library_id');
    }
}
