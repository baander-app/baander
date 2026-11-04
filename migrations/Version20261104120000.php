<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records on each worker boot the fingerprint of the deployment profile its manifest started
 * it with, so web health can report a web profile that disagrees with the active boot. Every
 * boot reserved from now on records one. Boots reserved earlier stay unknown (NULL): their
 * profile was never stored and cannot be derived from the manifest hash, and a constraint that
 * required a value would refuse to retire them.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the deployment profile fingerprint of each worker boot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE worker_deployment_boots
                ADD deployment_profile_fingerprint text,
                ADD CONSTRAINT chk_worker_deployment_boots_deployment_profile_fingerprint
                    CHECK (deployment_profile_fingerprint ~ '^[0-9a-f]{64}$')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE worker_deployment_boots DROP deployment_profile_fingerprint');
    }
}
