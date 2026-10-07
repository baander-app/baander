<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete OAuth access tokens and device codes with their user, and remove those a deleted user left without one.';
    }

    public function up(Schema $schema): void
    {
        // A token whose user was deleted under the former SET NULL rule authenticates no one.
        // Its refresh tokens and token metadata cascade.
        $this->addSql('DELETE FROM oauth_access_tokens WHERE user_id IS NULL');
        // Approving a device code binds it to a user, so an approved code without one lost its user
        // under the former SET NULL rule. Pending and denied codes have no user yet and are kept.
        $this->addSql('DELETE FROM oauth_device_codes WHERE approved AND user_id IS NULL');

        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT fk_oauth_access_tokens_user_id, ADD CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_device_codes DROP CONSTRAINT fk_oauth_device_codes_user_id, ADD CONSTRAINT fk_oauth_device_codes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Restores the delete rules only; the rows deleted by up() are not restored.
        $this->addSql('ALTER TABLE oauth_device_codes DROP CONSTRAINT fk_oauth_device_codes_user_id, ADD CONSTRAINT fk_oauth_device_codes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT fk_oauth_access_tokens_user_id, ADD CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }
}
