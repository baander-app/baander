<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115110000 extends AbstractMigration
{
    public const string RECOVERY_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e04';
    public const string RECOVERY_COMMAND = 'App\\Catalog\\Application\\Command\\RecoverAlbumMatchQueueCommand';
    public const string RECOVERY_EXPRESSION = '*/10 * * * *';

    public function getDescription(): string
    {
        return 'Schedule the album match queue\'s recovery every ten minutes.';
    }

    public function up(Schema $schema): void
    {
        // The queue's runs queue each other; a run lost to a failed dispatch or a message out of
        // retries would leave every request waiting. The schedule starts at the next ten-minute
        // mark, from one clock sample. The seed is installed data, not a change an admin made, so
        // its insert publishes no live update.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE scheduled_jobs DISABLE TRIGGER query_capture_insert');
        $this->addSql(
            <<<'SQL'
                WITH sampled_clock AS MATERIALIZED (SELECT clock_timestamp() AS now)
                INSERT INTO scheduled_jobs (
                    id, public_id, revision, name, expression, job_type, command, status,
                    description, parameters, created_at, updated_at, next_run_at, recovery_after, run_count
                )
                SELECT CAST(:id AS uuid), translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '-_'),
                    gen_random_uuid(), 'Recover the album match queue', :expression, 'messenger', :command, 'active',
                    'Starts the MusicBrainz album match queue again when a match is due and no queue run is in flight.',
                    CAST('[]' AS json), now, now,
                    date_bin(INTERVAL '10 minutes', now, TIMESTAMPTZ '2000-01-01 00:00:00+00') + INTERVAL '10 minutes',
                    now, 0
                FROM sampled_clock
                SQL,
            [
                'id' => self::RECOVERY_JOB_ID,
                'expression' => self::RECOVERY_EXPRESSION,
                'command' => self::RECOVERY_COMMAND,
            ],
        );
        $this->addSql('ALTER TABLE scheduled_jobs ENABLE TRIGGER query_capture_insert');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The album match queue\'s recovery schedule cannot be removed: without it, a lost queue run leaves every match request waiting.');
    }
}
