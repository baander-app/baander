<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

/**
 * Renders PostgreSQL's configuration from the deployment size. The operator sets shared buffers
 * and per-operation work memory; maintenance memory is half of shared buffers up to 256 MB,
 * autovacuum memory half of that, and the planner's cache estimate four times shared buffers.
 * Connections follow the pooler's server pools. Parallel query stays off so a query's memory
 * stays within one backend's work memory.
 */
final class PostgresConfiguration
{
    private const int MAXIMUM_MAINTENANCE_WORK_MEM_MIB = 256;

    /** @param array{application:int,control:int,direct:int} $pools */
    public static function render(int $sharedBuffersMiB, int $workMemMiB, array $pools): string
    {
        foreach (['sharedBuffersMiB' => $sharedBuffersMiB, 'workMemMiB' => $workMemMiB] as $key => $value) {
            [$minimum, $maximum] = HouseholdConfiguration::POSTGRES_BOUNDS[$key];
            if ($value < $minimum || $value > $maximum) {
                throw new \InvalidArgumentException(sprintf('PostgreSQL %s requires %d through %d MiB.', $key, $minimum, $maximum));
            }
        }
        $maintenance = min(intdiv($sharedBuffersMiB, 2), self::MAXIMUM_MAINTENANCE_WORK_MEM_MIB);
        $autovacuum = intdiv($maintenance, 2);
        $connections = Pooler::maxConnections($pools);
        $superuser = Pooler::SUPERUSER_SLOTS;
        $effectiveCache = 4 * $sharedBuffersMiB;

        return <<<CONF
            # Rendered from the deployment size.
            listen_addresses = '*'
            port = 5432
            # Clients reach PostgreSQL through the pooler: {$pools['application']} application and {$pools['control']} control
            # server connections, {$pools['direct']} direct connections and {$superuser} superuser slots.
            max_connections = {$connections}
            superuser_reserved_connections = {$superuser}
            reserved_connections = 0

            shared_buffers = {$sharedBuffersMiB}MB
            effective_cache_size = {$effectiveCache}MB
            work_mem = {$workMemMiB}MB
            maintenance_work_mem = {$maintenance}MB
            autovacuum_work_mem = {$autovacuum}MB
            max_parallel_workers_per_gather = 0
            autovacuum = on

            shared_preload_libraries = 'pg_stat_statements'

            # The application computes days and instants in UTC; a migration sets the same database default.
            timezone = 'UTC'

            checkpoint_completion_target = 0.9
            default_statistics_target = 100

            pgroonga.enable_wal = on
            pgroonga.max_wal_size = 100MB

            CONF;
    }
}
