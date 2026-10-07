<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_settings for each user\'s explicit setting choices.';
    }

    public function up(Schema $schema): void
    {
        // A row exists only for an explicit choice. The primary key leads with user_id, so it
        // also serves the foreign key. A JSON null would read as "no choice", so values are
        // limited to the scalar types a setting can take.
        $this->addSql(<<<'SQL'
            CREATE TABLE user_settings (
                user_id UUID NOT NULL,
                key TEXT NOT NULL,
                value JSONB NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT user_settings_pkey PRIMARY KEY (user_id, key),
                CONSTRAINT fk_user_settings_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_settings_value_scalar CHECK (jsonb_typeof(value) IN ('boolean', 'number', 'string'))
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_settings');
    }
}
