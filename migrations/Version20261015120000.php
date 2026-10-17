<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Stop application writers before running this transactional backfill. */
final class Version20261015120000 extends AbstractMigration
{
    protected const array TABLES = [
        'libraries',
        'genres',
        'passkeys',
        'login_blocks',
        'devices',
        'listening_sessions',
        'webhooks',
        'scheduled_jobs',
        'recommendations',
        'eq_device_profiles',
        'radio_sources',
        'radio_stations',
        'country_subscriptions',
        'scheduler_occurrences',
    ];

    public function getDescription(): string
    {
        return 'Add public identities, require passkey re-enrollment, retain scheduler job snapshots and recover missing active-session devices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        // Acquire the same lock that the following schema changes retain until
        // commit, before removing credentials, so no old writer can refill them.
        $this->addSql('LOCK TABLE passkeys IN ACCESS EXCLUSIVE MODE');
        // Authenticators retain their original WebAuthn user handle. Existing
        // credentials must be enrolled again with the user's public identity.
        $this->addSql('DELETE FROM passkeys');
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN public_id TEXT', $table));
            // The first 21 base64 symbols contain 126 random bits; replacing the
            // two punctuation symbols gives the PublicId alphabet without padding.
            $this->addSql(sprintf(
                "UPDATE %s SET public_id = translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '_-')",
                $table,
            ));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN public_id SET NOT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT uniq_%s_public_id UNIQUE (public_id)', $table, $table));
        }

        // Occurrences outlive their schedule. Keep the public route identity in
        // the immutable receipt, including one shared identity per deleted job.
        $this->addSql('ALTER TABLE scheduler_occurrences ADD COLUMN job_public_id TEXT');
        $this->addSql(<<<'SQL'
            UPDATE scheduler_occurrences o SET job_public_id = j.public_id
            FROM scheduled_jobs j WHERE j.id = o.job_id
            SQL);
        $this->addSql(<<<'SQL'
            WITH orphan_jobs AS MATERIALIZED (
                SELECT job_id,
                    translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '_-') AS public_id
                FROM (SELECT DISTINCT job_id FROM scheduler_occurrences WHERE job_public_id IS NULL) missing
            )
            UPDATE scheduler_occurrences o SET job_public_id = orphan_jobs.public_id
            FROM orphan_jobs WHERE orphan_jobs.job_id = o.job_id AND o.job_public_id IS NULL
            SQL);
        $this->addSql('ALTER TABLE scheduler_occurrences ALTER COLUMN job_public_id SET NOT NULL');

        // Older session claims could retain a browser token without registering
        // its device. Recover that relationship separately for each owning user.
        $this->addSql(<<<'SQL'
            INSERT INTO devices (id, public_id, user_id, device_id, created_at)
            SELECT uuidv7(), translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '_-'),
                s.user_id, s.active_device_id, clock_timestamp()
            FROM listening_sessions s
            WHERE s.active_device_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM devices d
                    WHERE d.user_id = s.user_id AND d.device_id = s.active_device_id
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing public identities would invalidate references already issued to clients.');
    }
}
