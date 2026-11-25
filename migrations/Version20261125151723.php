<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125151723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record whether a member or the generator wrote each recommendation, index member rows by user, and bound recommendation names to 255 characters.';
    }

    public function up(Schema $schema): void
    {
        // ALTER TABLE takes ACCESS EXCLUSIVE; fail rather than queue behind a generation run.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // A constant default adds the column without rewriting the table; the check scans it once.
        $this->addSql(<<<'SQL'
            ALTER TABLE recommendations
                ADD COLUMN origin text DEFAULT 'generator' NOT NULL,
                ADD CONSTRAINT chk_recommendations_origin CHECK (origin = ANY (ARRAY['generator'::text, 'member'::text]))
            SQL);
        // The API always stores a user, and the generator stores one only on 'collaborative' rows of
        // a run for that user, so a user's row under any other name was written by the member. A
        // member's own 'collaborative' row stays counted as the generator's. The capture triggers
        // ignore the change: origin is not one of the columns they compare.
        $this->addSql("UPDATE recommendations SET origin = 'member' WHERE user_id IS NOT NULL AND name <> 'collaborative'");
        // NOT VALID, after the update above, which would otherwise fail on a stored name over the
        // bound: new and changed rows are checked, a stored row stays readable and deletable.
        $this->addSql('ALTER TABLE recommendations ADD CONSTRAINT chk_recommendations_name_length CHECK (char_length(name) <= 255) NOT VALID');
        // The per-user count of member rows reads only member rows, however many rows a run
        // for that user generated.
        $this->addSql("CREATE INDEX idx_recommendations_user_id_member ON recommendations USING btree (user_id) WHERE (origin = 'member'::text)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_recommendations_user_id_member');
        $this->addSql('ALTER TABLE recommendations DROP CONSTRAINT chk_recommendations_name_length');
        $this->addSql('ALTER TABLE recommendations DROP CONSTRAINT chk_recommendations_origin, DROP COLUMN origin');
    }
}
