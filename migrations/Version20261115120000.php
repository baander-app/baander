<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the claim an album match queue run holds on a request, and the due time of a request made during the claim, in columns of their own.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // A run's claim was the request's due time moved ahead, so a new request for the album ended
        // the claim. The claim is now an ID and an expiry beside the due time, and a request made
        // while it lasts waits in next_due_at. Only a request can be claimed, and only a claimed
        // request has a request waiting beside it. Rows stored before are unclaimed. The capture
        // trigger does not watch these columns, so a claim publishes no live update.
        $this->addSql(<<<'SQL'
            ALTER TABLE album_match_states
                ADD claim_id uuid,
                ADD claim_expires_at timestamptz,
                ADD next_due_at timestamptz,
                ADD CONSTRAINT chk_album_match_states_claim CHECK (
                    (claim_id IS NULL) = (claim_expires_at IS NULL)
                    AND (claim_id IS NULL OR state = 'requested')
                    AND (next_due_at IS NULL OR claim_id IS NOT NULL)
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The album match claim cannot be removed: dropping its columns loses which request each queue run holds and the requests made meanwhile.');
    }
}
