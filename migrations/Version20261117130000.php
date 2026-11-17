<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store which users the host of a watch party has invited to it.';
    }

    public function up(Schema $schema): void
    {
        // Each foreign key locks the table it references against writes while it is added.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // One row per invited user and party. The row has a key of its own, so the party
        // and the user stay ordinary columns, and nothing but an insert or a delete writes it.
        $this->addSql(<<<'SQL'
            CREATE TABLE party_invitations (
                id uuid NOT NULL,
                session_id uuid NOT NULL,
                user_id uuid NOT NULL,
                created_at timestamptz NOT NULL,
                CONSTRAINT party_invitations_pkey PRIMARY KEY (id)
            )
            SQL);
        // An invitation ends with its party or its user.
        $this->addSql('ALTER TABLE party_invitations ADD CONSTRAINT fk_party_invitations_session_id FOREIGN KEY (session_id) REFERENCES party_sessions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE party_invitations ADD CONSTRAINT fk_party_invitations_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        // Leads with the user, so it also finds a deleted user's invitations; the party has its own index.
        $this->addSql('CREATE UNIQUE INDEX uniq_party_invitations_user_id_session_id ON party_invitations USING btree (user_id, session_id)');
        $this->addSql('CREATE INDEX idx_party_invitations_session_id ON party_invitations USING btree (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Party invitations cannot be removed: dropping the table loses which users each host invited.');
    }
}
