<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear the first-party flag of personal access clients and keep it clear with a check constraint.';
    }

    public function up(Schema $schema): void
    {
        // Adding the constraint takes ACCESS EXCLUSIVE on a table every token issuance reads;
        // fail fast rather than queue behind a running request. The table holds a few rows.
        $this->addSql("SET LOCAL lock_timeout = '2s'");

        // Personal access clients were created with the first-party flag, which let the consent
        // page approve their requests without asking. The capture triggers publish the change.
        $this->addSql('UPDATE oauth_clients SET first_party = FALSE WHERE personal_access_client AND first_party');
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_clients
                ADD CONSTRAINT chk_oauth_clients_personal_access_not_first_party
                    CHECK (NOT (personal_access_client AND first_party))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The cleared flags stay cleared: restoring them would let those clients skip consent again.
        $this->addSql('ALTER TABLE oauth_clients DROP CONSTRAINT chk_oauth_clients_personal_access_not_first_party');
    }
}
