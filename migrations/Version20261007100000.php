<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007100000 extends AbstractMigration
{
    /** The seeded daily purge; down() removes exactly this schedule. */
    public const string PURGE_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e01';
    public const string PURGE_COMMAND = 'App\\Auth\\Application\\Command\\OAuth\\PurgeExpiredOAuthCodesCommand';
    public const string PURGE_EXPRESSION = '30 3 * * *';

    public function getDescription(): string
    {
        return 'Store only the SHA-256 digest of OAuth client secrets, constrain client secrets and device code decisions, and schedule the daily purge of expired OAuth codes.';
    }

    public function up(Schema $schema): void
    {
        // Only confidential clients have a secret. An empty secret authenticates nobody.
        $this->addSql("UPDATE oauth_clients SET secret = NULL WHERE secret IS NOT NULL AND (NOT confidential OR secret = '')");
        // Lower-case hex SHA-256 of the UTF-8 secret, the digest ClientSecret computes in PHP.
        $this->addSql("UPDATE oauth_clients SET secret = encode(sha256(convert_to(secret, 'UTF8')), 'hex') WHERE secret IS NOT NULL");
        // A confidential client without a secret could never authenticate. It is revoked and
        // given the digest of no known secret (pgcrypto, enabled by Version001_InitialSchema),
        // so the client stays listed and an administrator can delete or replace it.
        $this->addSql("UPDATE oauth_clients SET revoked = TRUE, secret = encode(gen_random_bytes(32), 'hex'), updated_at = clock_timestamp() WHERE confidential AND secret IS NULL");
        // A revoked client keeps no usable tokens, as RevokeClientHandler ensures for later revocations.
        $this->addSql(<<<'SQL'
            UPDATE oauth_refresh_tokens AS refresh_token
            SET revoked = TRUE, updated_at = clock_timestamp()
            FROM oauth_access_tokens AS access_token
            JOIN oauth_clients AS client ON client.id = access_token.client_id
            WHERE refresh_token.access_token_id = access_token.id AND client.revoked AND NOT refresh_token.revoked
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE oauth_access_tokens AS access_token
            SET revoked = TRUE, updated_at = clock_timestamp()
            FROM oauth_clients AS client
            WHERE client.id = access_token.client_id AND client.revoked AND NOT access_token.revoked
            SQL);
        $this->addSql('ALTER TABLE oauth_clients RENAME COLUMN secret TO secret_hash');
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_clients
                ADD CONSTRAINT chk_oauth_clients_confidential_secret_hash CHECK (confidential = (secret_hash IS NOT NULL)),
                ADD CONSTRAINT chk_oauth_clients_secret_hash_format CHECK (secret_hash ~ '^[0-9a-f]{64}$')
            SQL);

        // No release has shipped, so device codes that break the new rules are deleted rather
        // than repaired: approval binds a user, and a request is approved or denied, not both.
        // A consumed code was approved first (DeviceCode::consume).
        $this->addSql('DELETE FROM oauth_device_codes WHERE (approved AND user_id IS NULL) OR (approved AND denied) OR (consumed_at IS NOT NULL AND NOT approved)');
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_device_codes
                ADD CONSTRAINT chk_oauth_device_codes_approved_user_id CHECK (NOT approved OR user_id IS NOT NULL),
                ADD CONSTRAINT chk_oauth_device_codes_single_decision CHECK (NOT (approved AND denied)),
                ADD CONSTRAINT chk_oauth_device_codes_consumed_approved CHECK (consumed_at IS NULL OR approved)
            SQL);

        // uniq_oauth_device_codes_user_code stays a full unique index. A partial index cannot
        // test expiry (now() is not immutable), so it could only exclude decided codes; then
        // findByUserCode could match several rows and a stale code could name a new request.
        // The daily purge keeps about a day of codes, against 20^8 possible user codes.

        // Daily at 03:30 UTC. The scheduler starts evaluating a new schedule from its first
        // poll (evaluated_through stays NULL), so no run is caught up for the past.
        $this->addSql(
            <<<'SQL'
                INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, description, parameters, created_at, updated_at, next_run_at, run_count)
                VALUES (:id, gen_random_uuid(), :name, :expression, 'messenger', :command, 'active', :description, '[]', clock_timestamp(), clock_timestamp(),
                    date_trunc('day', clock_timestamp(), 'UTC') + INTERVAL '3 hours 30 minutes'
                        + CASE WHEN date_trunc('day', clock_timestamp(), 'UTC') + INTERVAL '3 hours 30 minutes' <= clock_timestamp() THEN INTERVAL '1 day' ELSE INTERVAL '0' END,
                    0)
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => self::PURGE_JOB_ID,
                'name' => 'Purge expired OAuth codes',
                'expression' => self::PURGE_EXPRESSION,
                'command' => self::PURGE_COMMAND,
                'description' => 'Deletes authorization codes and device codes that expired more than an hour ago.',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // Restores the schema only. Plain-text secrets cannot be recovered from their digests,
        // so the secret column keeps digests; deleted device codes and revocations stay.
        $this->addSql('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => self::PURGE_JOB_ID]);
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_device_codes
                DROP CONSTRAINT chk_oauth_device_codes_consumed_approved,
                DROP CONSTRAINT chk_oauth_device_codes_single_decision,
                DROP CONSTRAINT chk_oauth_device_codes_approved_user_id
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_clients
                DROP CONSTRAINT chk_oauth_clients_secret_hash_format,
                DROP CONSTRAINT chk_oauth_clients_confidential_secret_hash
            SQL);
        $this->addSql('ALTER TABLE oauth_clients RENAME COLUMN secret_hash TO secret');
    }
}
