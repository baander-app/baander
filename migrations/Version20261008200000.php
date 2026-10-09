<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn the library scan claim into a lease: the claim holder\'s token and the instant its claim lapses unless renewed.';
    }

    public function up(Schema $schema): void
    {
        // A `scanning` status without a lease has no holder that could renew or end it.
        $this->addSql("UPDATE libraries SET scan_status = 'failed' WHERE scan_status = 'scanning'");
        $this->addSql('ALTER TABLE libraries ADD scan_claim_id UUID DEFAULT NULL, ADD scan_claim_expires_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_libraries_scan_claim_id ON libraries (scan_claim_id)');
        // A library is `scanning` exactly while a claim with a lease holds it.
        $this->addSql(<<<'SQL'
            ALTER TABLE libraries ADD CONSTRAINT chk_libraries_scan_claim CHECK (
                (scan_claim_id IS NULL) = (scan_claim_expires_at IS NULL)
                AND (scan_claim_id IS NOT NULL) = (scan_status IS NOT DISTINCT FROM 'scanning')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE libraries DROP CONSTRAINT chk_libraries_scan_claim');
        $this->addSql('DROP INDEX uniq_libraries_scan_claim_id');
        $this->addSql('ALTER TABLE libraries DROP scan_claim_id, DROP scan_claim_expires_at');
    }
}
