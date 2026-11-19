<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store TOTP secrets encrypted and record the last time step each account spent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // Plaintext secrets are not carried over: every TOTP enrolment ends here, and its
        // owner enrols again. Dropping the column fires no capture trigger.
        $this->addSql('ALTER TABLE users DROP COLUMN totp_secret');
        $this->addSql('ALTER TABLE users ADD totp_secret_ciphertext BYTEA DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_last_used_step BIGINT DEFAULT NULL');

        // The row capture function reports enabling or disabling TOTP as an account change
        // without exposing the secret; it now reads the ciphertext column. The body is the
        // one Version20261030210000 created, with only that column renamed.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION query_live_capture_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                old_data jsonb;
                new_data jsonb;
                relevant text[] := TG_ARGV[0]::text[];
            BEGIN
                IF TG_OP<>'INSERT' THEN old_data:=to_jsonb(OLD); END IF;
                IF TG_OP<>'DELETE' THEN new_data:=to_jsonb(NEW); END IF;
                IF TG_OP='UPDATE' AND NOT EXISTS (SELECT 1 FROM unnest(relevant) c WHERE old_data->c IS DISTINCT FROM new_data->c) THEN
                    IF TG_TABLE_NAME='users' AND ((old_data->>'totp_secret_ciphertext' IS NULL) IS DISTINCT FROM (new_data->>'totp_secret_ciphertext' IS NULL)) THEN
                        INSERT INTO query_change_inbox(family,scope_kind,scope_id)
                        SELECT DISTINCT 'account','user',principal
                        FROM (VALUES (old_data->>'id'),(new_data->>'id')) recipients(principal)
                        WHERE principal IS NOT NULL;
                    END IF;
                    RETURN NEW;
                END IF;
                INSERT INTO query_change_inbox(family,scope_kind,scope_id)
                SELECT DISTINCT resolved.family,resolved.scope_kind,resolved.scope_id
                FROM (SELECT old_data row_data WHERE old_data IS NOT NULL UNION SELECT new_data WHERE new_data IS NOT NULL) images
                CROSS JOIN LATERAL query_live_capture(TG_TABLE_NAME,images.row_data,TG_ARGV[1]::text[],TG_ARGV[2]::text[]) resolved;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The plaintext TOTP secrets were dropped and cannot be restored.');
    }
}
