<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduler;

use Doctrine\DBAL\Connection;

/** Adds the current identity columns to isolated historical scheduler schemas. */
final class PublicIdentitySchema
{
    public static function upgrade(Connection $connection): void
    {
        foreach (['scheduled_jobs' => ['public_id'], 'scheduler_occurrences' => ['public_id', 'job_public_id']] as $table => $columns) {
            if ($connection->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table]) === false) { continue; }
            foreach ($columns as $column) {
                $connection->executeStatement('ALTER TABLE ' . $table . ' ADD COLUMN IF NOT EXISTS ' . $column . ' TEXT NOT NULL');
            }
            $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_public_id ON ' . $table . ' (public_id)');
        }
    }
}
