<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store authorization codes and device codes as SHA-256 hashes.';
    }

    public function up(Schema $schema): void
    {
        // oauth_auth_codes.code_id and oauth_device_codes.device_code now hold the hex SHA-256
        // digest of the code, under their existing unique indexes. The stored rows hold the codes
        // themselves, which a database reader could redeem. A code lives for a minute and a device
        // code for 15 minutes by default, so the rows are deleted rather than hashed in place, and
        // a device in the middle of pairing starts again. DELETE, not TRUNCATE, so the device code
        // capture triggers record the deletions for live clients.
        $this->addSql('DELETE FROM oauth_auth_codes');
        $this->addSql('DELETE FROM oauth_device_codes');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted authorization and device codes cannot be restored, and the previous version looks codes up by their plain value, which storage no longer holds.');
    }
}
