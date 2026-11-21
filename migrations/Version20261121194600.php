<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121194600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store individual Artist links, identity conflicts and merged Person aliases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE person_artist_links (
                artist_id UUID NOT NULL,
                person_id UUID DEFAULT NULL,
                artist_mbid TEXT DEFAULT NULL,
                managed_by_operator BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (artist_id),
                CONSTRAINT fk_person_artist_links_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
                CONSTRAINT fk_person_artist_links_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_person_artist_links_person_id ON person_artist_links (person_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE person_id_aliases (
                public_id TEXT NOT NULL,
                person_id UUID NOT NULL,
                PRIMARY KEY (public_id),
                CONSTRAINT fk_person_id_aliases_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_person_id_aliases_person_id ON person_id_aliases (person_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE person_identifier_blocks (
                namespace TEXT NOT NULL,
                external_id TEXT NOT NULL,
                PRIMARY KEY (namespace, external_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE person_identity_conflicts (
                claim_key TEXT NOT NULL,
                claims JSONB NOT NULL,
                owners JSONB NOT NULL,
                source TEXT NOT NULL,
                observed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (claim_key)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping the person links and aliases would discard operator decisions.');
    }
}
