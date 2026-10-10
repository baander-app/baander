<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give the library claim a kind: a scan, or a delete with files that leaves the scan status alone.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE libraries DROP CONSTRAINT chk_libraries_scan_claim');
        $this->addSql('ALTER TABLE libraries RENAME COLUMN scan_claim_id TO claim_id');
        $this->addSql('ALTER TABLE libraries RENAME COLUMN scan_claim_expires_at TO claim_expires_at');
        $this->addSql('ALTER INDEX uniq_libraries_scan_claim_id RENAME TO uniq_libraries_claim_id');
        $this->addSql('ALTER TABLE libraries ADD claim_kind TEXT DEFAULT NULL');
        // Every claim held until now is a scan's.
        $this->addSql("UPDATE libraries SET claim_kind = 'scan' WHERE claim_id IS NOT NULL");
        // A claim has a holder, a kind and a lease together; `scanning` goes with a scan claim
        // only, so a delete claim holds the library without changing its scan status.
        $this->addSql(<<<'SQL'
            ALTER TABLE libraries ADD CONSTRAINT chk_libraries_claim CHECK (
                (claim_id IS NULL) = (claim_expires_at IS NULL)
                AND (claim_id IS NULL) = (claim_kind IS NULL)
                AND (claim_kind IS NULL OR claim_kind IN ('scan', 'delete'))
                AND (claim_kind IS NOT DISTINCT FROM 'scan') = (scan_status IS NOT DISTINCT FROM 'scanning')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The earlier schema knows only scan claims, so delete claims are dropped.
        $this->addSql('ALTER TABLE libraries DROP CONSTRAINT chk_libraries_claim');
        $this->addSql("UPDATE libraries SET claim_id = NULL, claim_expires_at = NULL WHERE claim_kind = 'delete'");
        $this->addSql('ALTER TABLE libraries DROP claim_kind');
        $this->addSql('ALTER INDEX uniq_libraries_claim_id RENAME TO uniq_libraries_scan_claim_id');
        $this->addSql('ALTER TABLE libraries RENAME COLUMN claim_expires_at TO scan_claim_expires_at');
        $this->addSql('ALTER TABLE libraries RENAME COLUMN claim_id TO scan_claim_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE libraries ADD CONSTRAINT chk_libraries_scan_claim CHECK (
                (scan_claim_id IS NULL) = (scan_claim_expires_at IS NULL)
                AND (scan_claim_id IS NOT NULL) = (scan_status IS NOT DISTINCT FROM 'scanning')
            )
            SQL);
    }
}
