<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007130000 extends AbstractMigration
{
    /** The seeded daily generation; down() removes exactly this schedule. */
    public const string GENERATION_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e02';
    public const string GENERATION_COMMAND = 'App\\Recommendation\\Application\\Command\\GenerateRecommendationsCommand';
    public const string GENERATION_EXPRESSION = '0 4 * * *';
    /** Constructor arguments by name; `automatic` makes each run yield to recommendations.auto_generate. */
    public const string GENERATION_PARAMETERS = '{"mode":"incremental","automatic":true}';

    public function getDescription(): string
    {
        return 'Schedule the daily recommendation generation, which runs while recommendations.auto_generate is on.';
    }

    public function up(Schema $schema): void
    {
        // Daily at 04:00 UTC, after the 03:30 OAuth code purge. The scheduler starts evaluating
        // a new schedule from its first poll (evaluated_through stays NULL), so no run is caught
        // up for the past. parameters is json, not jsonb: the scheduler compares the stored text.
        $this->addSql(
            <<<'SQL'
                INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, description, parameters, created_at, updated_at, next_run_at, run_count)
                VALUES (:id, gen_random_uuid(), :name, :expression, 'messenger', :command, 'active', :description, CAST(:parameters AS json), clock_timestamp(), clock_timestamp(),
                    date_trunc('day', clock_timestamp(), 'UTC') + INTERVAL '4 hours'
                        + CASE WHEN date_trunc('day', clock_timestamp(), 'UTC') + INTERVAL '4 hours' <= clock_timestamp() THEN INTERVAL '1 day' ELSE INTERVAL '0' END,
                    0)
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => self::GENERATION_JOB_ID,
                'name' => 'Generate recommendations',
                'expression' => self::GENERATION_EXPRESSION,
                'command' => self::GENERATION_COMMAND,
                'description' => 'Regenerates recommendation snapshots while the recommendations.auto_generate setting is on.',
                'parameters' => self::GENERATION_PARAMETERS,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => self::GENERATION_JOB_ID]);
    }
}
