<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the user_theme_moods table backing the UserThemeMoodEntity.
 *
 * The entity (src/UserPreference/Infrastructure/Doctrine/Entity/UserThemeMoodEntity.php)
 * previously had no migration, so the table was absent from any migration-driven
 * database (test env, fresh installs), causing the /api/user/theme-mood endpoints to
 * throw a 500 (TableNotFoundException).
 */
final class Version420260613CreateUserThemeMoods extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_theme_moods table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_theme_moods (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            theme_mood TEXT DEFAULT \'dark\' NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX user_theme_moods_user_id_key ON user_theme_moods (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_theme_moods');
    }
}
