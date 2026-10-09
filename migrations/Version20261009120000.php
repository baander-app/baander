<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unique index on lyrics.lrclib_id: songs on an album and a compilation, or in two libraries, share one LRCLIB record.';
    }

    public function up(Schema $schema): void
    {
        // No query filters or joins on lrclib_id, so no plain index replaces it.
        $this->addSql('DROP INDEX uniq_lyrics_lrclib_id');
    }

    public function down(Schema $schema): void
    {
        // Fails with a unique violation, rather than delete lyrics, once two songs share a record.
        $this->addSql('CREATE UNIQUE INDEX uniq_lyrics_lrclib_id ON lyrics (lrclib_id)');
    }
}
