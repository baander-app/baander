<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\{CommandExecutor, EngineExecutor, ExecutionContext};
use Baander\Tooling\Resources\{ResourceLedger, ResourceKind, DisposableContainer};
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

class Database
{
    public const string CATALOG_SQL = <<<'SQL'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '15s';
SET LOCAL lock_timeout = '3s';
SET LOCAL timezone = 'UTC';
SELECT json_build_object(
 'server_version', current_setting('server_version_num')::int,
 'database', current_database(),
 'owner', pg_get_userbyid((SELECT datdba FROM pg_database WHERE datname=current_database())),
 'extensions', (SELECT coalesce(json_object_agg(extname,extversion),'{}'::json) FROM pg_extension),
 'objects', (SELECT coalesce(json_agg(n.nspname || '.' || identified.identity ORDER BY n.nspname,identified.identity),'[]'::json)
 FROM pg_depend d JOIN pg_namespace n ON n.oid=d.refobjid
 CROSS JOIN LATERAL pg_identify_object(d.classid,d.objid,d.objsubid) identified
 WHERE d.refclassid='pg_namespace'::regclass AND n.nspname <> 'information_schema'
 AND n.nspname !~ '^pg_' AND d.classid <> 'pg_extension'::regclass
 AND NOT EXISTS (SELECT 1 FROM pg_depend e WHERE e.classid=d.classid AND e.objid=d.objid AND e.deptype='e')
 AND NOT (identified.type='table' AND identified.name='doctrine_migration_versions' AND n.nspname=current_schema())),
 'metadata', to_regclass('doctrine_migration_versions') IS NOT NULL
);
SELECT CASE WHEN to_regclass('doctrine_migration_versions') IS NOT NULL THEN
 'SELECT json_build_object(''versions'',coalesce(json_agg(version ORDER BY version),''[]''::json)) FROM doctrine_migration_versions;'
 ELSE 'SELECT json_build_object(''versions'',''[]''::json);' END \gexec
COMMIT;
SQL;

    private readonly Connections $connections;

    public function __construct(
        private readonly StateStore $store,
        private readonly CommandExecutor $runner,
        ?Connections $connections = null,
    ) {
        $this->connections = $connections ?? new Connections($runner, Environment::clean());
    }

    /** @param array<string,mixed> $instance */
    public function docker(array $instance): \Baander\Tooling\Docker\Client
    {
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        return $docker;
    }

    /** @param array<string,mixed> $instance */
    public function container(array $instance, \Baander\Tooling\Docker\Client $docker): string
    {
        if (!empty($instance['adoption']) && empty($instance['ownership_transferred'])) {
            return $instance['adoption']['containers']['postgres'];
        }
        $identifier = trim($docker->compose($instance['project'], $instance['compose_file'], ['ps', '-q', 'postgres'])->stdout);
        if ($identifier === '') {
            throw new WorkflowError('The recorded PostgreSQL container is unavailable.');
        }
        return $identifier;
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function sql(array $instance, string $sql, ?string $container = null): array
    {
        $docker = $this->docker($instance);
        $identifier = $container ?? $this->container($instance, $docker);
        $result = $docker->run([
            'exec',
            '-i',
            $identifier,
            '/bin/sh',
            '-ec',
            'export PGPASSWORD="$POSTGRES_PASSWORD" PGCONNECT_TIMEOUT=5; exec psql -X -qAt --set=ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
        ], input: $sql, timeout: 30);
        $rows = [];
        $found = false;
        foreach (explode("\n", $result->stdout) as $line) {
            if (str_starts_with($line, '{')) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($row)) {
                    throw new WorkflowError('Malformed PostgreSQL catalog evidence.');
                }
                $rows = array_replace($rows, $row);
                $found = true;
            }
        }
        if (!$found) {
            throw new WorkflowError('PostgreSQL returned no catalog evidence.');
        }
        return $rows;
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function inspect(array $instance, array $release): array
    {
        $files = glob($release['source'] . '/migrations/Version*.php') ?: [];
        sort($files, SORT_STRING);
        if (
            $files === []
            || array_any(
                $files,
                static fn(string $file): bool => preg_match('/^Version\d{14}\.php$/D', basename($file)) !== 1,
            )
        ) {
            throw new WorkflowError('The target migration inventory is missing or malformed.');
        }
        $expected = array_map(
            static fn(string $file): string => 'DoctrineMigrations\\' . pathinfo($file, PATHINFO_FILENAME),
            $files,
        );
        $catalog = $this->sql($instance, self::CATALOG_SQL);
        $versions = $catalog['versions'] ?? null;
        if (
            !is_array($versions)
            || !array_is_list($versions)
            || array_any($versions, static fn(mixed $version): bool => !is_string($version))
        ) {
            throw new WorkflowError('Malformed PostgreSQL migration history.');
        }
        if ($versions !== array_slice($expected, 0, count($versions))) {
            throw new WorkflowError('Unknown, former, ahead-of-target or incomplete migration history; preserve this database and reconcile it explicitly.');
        }
        if ($versions === [] && !empty($catalog['objects'])) {
            throw new WorkflowError('Populated unversioned database refused before application metadata initialization.');
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[basename($file)] = hash_file('sha256', $file);
        }
        foreach ($instance['migration_hashes'] ?? [] as $name => $digest) {
            if (isset($hashes[$name]) && $hashes[$name] !== $digest) {
                throw new WorkflowError('An applied migration changed; reconcile the saved release before continuing.');
            }
        }
        return array_replace($catalog, [
            'fresh' => $versions === [],
            'pending' => array_slice($expected, count($versions)),
            'migration_hashes' => array_slice($hashes, 0, count($versions), true),
        ]);
    }

    /** @param array<string,mixed> $instance */
    public static function quiescent(array $instance): void
    {
        if (($instance['writers_stopped'] ?? null) !== true) {
            throw new WorkflowError('Stop and account for all database, queue and filesystem writers before checkpointing or migration.');
        }
    }

    /** @param array<string,mixed> $instance */
    private function assertNoWriters(array $instance, string $operation): void
    {
        $activity = $this->sql(
            $instance,
            "BEGIN READ ONLY; SELECT json_build_object('writers',count(*)) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND backend_type='client backend'; COMMIT;",
        );
        if (!empty($activity['writers'])) {
            throw new WorkflowError('Unaccounted database writers prevent ' . $operation . '.');
        }
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public function checkpoint(array $instance, array $release): array
    {
        self::quiescent($instance);
        $receipt = $this->store->directory($instance['id']) . '/checkpoint-resources.json';
        if (file_exists($receipt) || is_link($receipt)) {
            throw new WorkflowError('Recover unresolved checkpoint resources before creating another checkpoint: ' . $receipt);
        }
        $catalog = $this->inspect($instance, $release);
        $docker = $this->docker($instance);
        $container = $this->container($instance, $docker);
        $this->assertNoWriters($instance, 'a quiescent checkpoint');
        $directory = $this->store->directory($instance['id']) . '/checkpoints/' . bin2hex(random_bytes(16));
        PrivateDirectory::prepare($directory);
        $archive = '/tmp/baander-checkpoint-' . basename($directory) . '.dump';
        $transferred = false;
        try {
            $docker->run([
                'exec',
                $container,
                '/bin/sh',
                '-ec',
                'umask 077; export PGPASSWORD="$POSTGRES_PASSWORD" PGCONNECT_TIMEOUT=5; exec pg_dump --format=custom --file="$1" -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
                'checkpoint',
                $archive,
            ], timeout: null);
            $docker->run(['cp', $container . ':' . $archive, $directory . '/database.dump'], timeout: null);
            $transferred = true;
        } finally {
            // Keep the source archive when transfer is unconfirmed. Its container is retained instance ownership.
            if ($transferred) {
                $cleanup = new EngineExecutor(ExecutionContext::bounded($release['effective']['budgets']['cleanup'], $this->connections->environment(), ignoreCancellation: true));
                try {
                    $cleanupDocker = new Client($cleanup, $docker->target, $this->connections->environment());
                    $cleanupDocker->validateIdentity();
                    $cleanupDocker->run(['exec', $container, '/bin/sh', '-ec', 'rm -f -- "$1"; test ! -e "$1"', 'checkpoint-cleanup', $archive]);
                } finally {
                    $cleanup->close();
                }
            }
        }
        if (!is_file($directory . '/database.dump') || filesize($directory . '/database.dump') === 0) {
            throw new WorkflowError('Database checkpoint is missing or empty; live migration remains blocked.');
        }
        chmod($directory . '/database.dump', 0600);
        copy($this->store->directory($instance['id']) . '/env.json', $directory . '/env.json');
        chmod($directory . '/env.json', 0600);
        $model = new Runtime($this->store, $this->runner, $this->connections)->config($instance, $release);
        $redisId = empty($instance['ownership_transferred']) ? ($instance['adoption']['containers']['redis'] ?? null) : null;
        $redisId = $redisId ?: trim($docker->compose($instance['project'], $instance['compose_file'], ['ps', '-q', 'redis'])->stdout);
        if ($redisId === '') {
            throw new WorkflowError('The recorded Redis container is unavailable for a consistent checkpoint.');
        }
        $response = $docker->run([
            'exec',
            $redisId,
            '/bin/sh',
            '-ec',
            'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli --raw CONFIG GET dir',
        ])->stdout
            |> (static fn(string $output): string => rtrim($output, "\r\n"))
            |> (static fn(string $output): array => explode("\n", $output));
        if (
            count($response) !== 2
            || $response[0] !== 'dir'
            || !($response[1] === '/data' || str_starts_with($response[1], '/data/'))
        ) {
            throw new WorkflowError('Redis persistence is outside its recorded /data mount or cannot be inspected; preserve the original service.');
        }
        if (trim($docker->run([
            'exec',
            $redisId,
            '/bin/sh',
            '-ec',
            'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli --raw SAVE',
        ])->stdout) !== 'OK') {
            throw new WorkflowError('Redis did not confirm persistence; preserve the original service.');
        }
        $docker->run(['stop', '--time', '30', $redisId], timeout: 45);
        $observed = json_decode($docker->run(['inspect', $redisId])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
        if ($observed['Id'] !== $redisId || $observed['State']['Running']) {
            throw new WorkflowError('Redis did not stop; writable persistence cannot be checkpointed consistently.');
        }
        $ledger = new ResourceLedger($receipt, $docker, $this->connections->environment());
        $snapshots = $ledger->scoped(function () use ($instance, $release, $model, $directory, $docker, $ledger): array {
            $snapshots = [];
            foreach (['redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state'] as $key) {
                $nested = $key === 'transcode_state'
                    && !empty($instance['adoption'])
                    && empty($instance['ownership_transferred'])
                    && !isset($instance['state_mounts'][$key]);
                $mount = $instance['state_mounts'][$nested ? 'var' : $key] ?? [
                    'type' => 'volume',
                    'source' => $model['volumes'][$key]['name'],
                ];
                $name = 'baander-checkpoint-' . bin2hex(random_bytes(16));
                $command = $nested
                    ? 'umask 077; if [ -d /snapshot/transcode_state ]; then tar -cpf /tmp/state.tar -C /snapshot/transcode_state .; else tar -cpf /tmp/state.tar --files-from /dev/null; fi'
                    : 'umask 077; tar -cpf /tmp/state.tar -C /snapshot .';
                $ledger->create(ResourceKind::Container, $name, [
                    '--network',
                    'none',
                    '--user',
                    '0:0',
                    '--entrypoint',
                    '/bin/sh',
                    '--mount',
                    'type=' . $mount['type'] . ',src=' . $mount['source'] . ',dst=/snapshot,readonly',
                    $release['images']['app_image']['resolvedId'],
                    '-ec',
                    $command,
                ]);
                $docker->run(['start', '--attach', $name], timeout: null);
                if (trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $name])->stdout) !== '0') {
                    throw new WorkflowError('Writable state checkpoint failed; preserve the database archive.');
                }
                $target = $directory . '/' . $key . '.tar';
                $docker->run(['cp', $name . ':/tmp/state.tar', $target], timeout: null);
                chmod($target, 0600);
                $snapshots[] = $key;
            }
            return $snapshots;
        }, $release['effective']['budgets']['cleanup']);
        $checkpoint = [
            'directory' => $directory,
            'catalog' => $catalog,
            'state' => $snapshots,
            'verified' => false,
            'postgres_image' => $instance['postgres_image'] ?? $release['images']['postgres_image']['resolvedId'],
            'target_revision' => $release['revision'],
            'archive_sha256' => hash_file('sha256', $directory . '/database.dump'),
        ];
        AtomicFile::writeJson($directory . '/checkpoint.json', $checkpoint);
        return $checkpoint;
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @param array<string,mixed> $checkpoint
     * @return array<string,mixed>
     */
    public function rehearse(array $instance, array $release, array $checkpoint): array
    {
        $directory = $checkpoint['directory'];
        $receipt = $directory . '/rehearsal-resources.json';
        if (file_exists($receipt) || is_link($receipt)) {
            throw new WorkflowError('Recover unresolved rehearsal resources before retrying: ' . $receipt);
        }
        $docker = $this->docker($instance);
        if (hash_file('sha256', $directory . '/database.dump') !== $checkpoint['archive_sha256']) {
            throw new WorkflowError('Database archive changed after checkpoint creation.');
        }
        if ($checkpoint['target_revision'] !== $release['revision']) {
            throw new WorkflowError('Checkpoint belongs to a different target release.');
        }
        $name = 'baander-rehearsal-' . bin2hex(random_bytes(8));
        $network = $name . '-private';
        $env = new Runtime($this->store, $this->runner, $this->connections)->environment($instance);
        $url = 'postgresql://' . rawurlencode($env['DB_USERNAME'])
            . ':' . rawurlencode($env['DB_PASSWORD'])
            . '@database:5432/' . rawurlencode($env['DB_DATABASE'])
            . '?serverVersion=18&charset=utf8';
        $commandEnvironment = array_replace($this->connections->environment(), array_intersect_key($env, array_flip(['DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'APP_SECRET'])), [
            'POSTGRES_PASSWORD' => $env['DB_PASSWORD'], 'POSTGRES_USER' => $env['DB_USERNAME'],
            'POSTGRES_DB' => $env['DB_DATABASE'], 'DATABASE_URL' => $url,
        ]);
        $docker = new Client($docker->executor, $docker->target, $commandEnvironment);
        $ledger = new ResourceLedger($receipt, $docker, $this->connections->environment());
        $result = $ledger->scoped(function () use ($docker, $ledger, $network, $name, $instance, $release, $checkpoint, $directory): array {
            $ledger->create(ResourceKind::Network, $network, ['--internal']);
            $ledger->create(ResourceKind::Container, $name, [
                '--network',
                $network,
                '--network-alias',
                'database',
                '--env',
                'POSTGRES_PASSWORD',
                '--env',
                'POSTGRES_USER',
                '--env',
                'POSTGRES_DB',
                $checkpoint['postgres_image'],
            ]);
            $docker->run(['start', $name]);
            $docker->run([
                'exec',
                $name,
                '/bin/sh',
                '-ec',
                'for n in $(seq 1 60); do pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" && exit 0; sleep 1; done; exit 1',
            ], timeout: 70);
            $docker->run(['cp', $directory . '/database.dump', $name . ':/tmp/database.dump'], timeout: null);
            $docker->run([
                'exec',
                $name,
                '/bin/sh',
                '-ec',
                'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_restore --exit-on-error --single-transaction -U "$POSTGRES_USER" -d "$POSTGRES_DB" /tmp/database.dump',
            ], timeout: null);
            $restored = $this->sql($instance, self::CATALOG_SQL, container: $name);
            foreach (['versions', 'objects', 'extensions', 'owner'] as $key) {
                if (($restored[$key] ?? null) != ($checkpoint['catalog'][$key] ?? null)) {
                    throw new WorkflowError('Restored catalog differs from the checkpoint; live migration remains blocked.');
                }
            }
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $candidate = $name . '-app-' . $attempt;
                $arguments = [
                    '--network',
                    $network,
                    '--user',
                    '0:0',
                    '--env',
                    'DB_HOST=database',
                    '--env',
                    'DATABASE_URL',
                    '--env',
                    'APP_ENV=prod',
                    '--env',
                    'APP_DEBUG=0',
                ];
                foreach (['DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'APP_SECRET'] as $key) {
                    array_push($arguments, '--env', $key);
                }
                array_push(
                    $arguments,
                    '--entrypoint',
                    '/bin/sh',
                    $release['images']['app_image']['resolvedId'],
                    '-ec',
                    'mkdir -p config/secrets; tar -xpf /tmp/secrets.tar -C config/secrets; exec php -d memory_limit=512M bin/console "$@"',
                    'rehearsal',
                    'doctrine:migrations:migrate',
                    '--no-interaction',
                );
                $ledger->create(ResourceKind::Container, $candidate, $arguments);
                $docker->run(['cp', $directory . '/secrets.tar', $candidate . ':/tmp/secrets.tar'], timeout: null);
                $docker->run(['start', '--attach', $candidate], timeout: null);
                $result = json_decode($docker->run(['inspect', $candidate])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
                if ($result['State']['ExitCode'] !== 0) {
                    throw new WorkflowError('Isolated migration rehearsal failed; live migration remains blocked.');
                }
            }
            $checkpoint['verified'] = true;
            return $checkpoint;
        }, $release['effective']['budgets']['cleanup']);
        AtomicFile::writeJson($directory . '/checkpoint.json', $result);
        return $result;
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     * @param array<string,mixed>|null $checkpoint
     * @return array<string,mixed>
     */
    public function migrate(array $instance, array $release, ?array $checkpoint = null): array
    {
        self::quiescent($instance);
        $this->assertNoWriters($instance, 'migration');
        $catalog = $this->inspect($instance, $release);
        if (!$catalog['fresh'] && (!empty($catalog['pending']) || !empty($instance['adoption']))) {
            if (
                $checkpoint === null
                || ($checkpoint['verified'] ?? null) !== true
                || ($checkpoint['target_revision'] ?? null) !== $release['revision']
            ) {
                throw new WorkflowError('A verified isolated restore and migration rehearsal is required.');
            }
        }
        $instance = array_replace($instance, [
            'phase' => 'database_mutating',
            'database_mutation_started' => true,
            'target_revision' => $release['revision'] ?? null,
        ]);
        $this->store->save($instance['id'], $instance);
        $docker = $this->docker($instance);
        try {
            $container = new Runtime($this->store, $this->runner, $this->connections)->applicationContainer($instance, $release, [
                'php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction',
            ]);
            DisposableContainer::run(
                $docker,
                $this->store->directory($instance['id']) . '/migration-resources.json',
                $container['arguments'],
                $this->connections->environment(),
                $release['effective']['budgets']['cleanup'],
                timeout: null,
                containerEnvironment: $container['environment'],
            );
        } catch (\Throwable $error) {
            $this->store->save($instance['id'], array_replace($instance, [
                'phase' => 'blocked',
                'failure' => 'Database mutation confirmation lost; reconcile this attempt before starting any release.',
            ]));
            throw $error;
        }
        $catalog = $this->inspect($instance, $release);
        if (!empty($catalog['pending'])) {
            throw new WorkflowError('Target migrations remain pending; preserve maintenance state.');
        }
        return array_replace($instance, [
            'migration_hashes' => $catalog['migration_hashes'],
            'database_mutation_confirmed' => true,
        ]);
    }
}
