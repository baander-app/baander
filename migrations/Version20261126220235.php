<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126220235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the media type of Ogg Opus songs as audio/ogg, the type RFC 7845 registers for Ogg Opus files.';
    }

    public function up(Schema $schema): void
    {
        // A scan stores the type its extension names, so only a .opus file was stored as audio/opus.
        // The capture triggers compare mime_type, so each changed song publishes a live update.
        $this->addSql("UPDATE songs SET mime_type = 'audio/ogg' WHERE mime_type = 'audio/opus'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE songs SET mime_type = 'audio/opus' WHERE mime_type = 'audio/ogg' AND lower(path) LIKE '%.opus'");
    }
}
