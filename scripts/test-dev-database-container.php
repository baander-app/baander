#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Tooling\Development\Bootstrap;
use Baander\Tooling\Development\Database;
use Baander\Tooling\Development\Docker;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Inventory\SafeTar;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

function drillCheck(bool $condition, string $message = 'Database drill assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function drillLines(string $value): array
{
    return $value === '' ? [] : explode("\n", $value);
}

function drillSql(Docker $docker, string $container, string $statement): string
{
    return $docker->run(
        [
            'exec', '-i', $container, '/bin/sh', '-ec',
            'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -X -qAt --set=ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
        ],
        input: $statement,
    )->stdout |> trim(...);
}

function drillRedis(Docker $docker, string $container, string ...$arguments): string
{
    return $docker->run([
        'exec', $container, '/bin/sh', '-ec',
        'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli --raw "$@"',
        'redis-check', ...$arguments,
    ])->stdout |> trim(...);
}

final class DatabaseDrillRunner extends Runner
{
    /** @var list<string> */
    public array $privateValues = [];
    /** @var list<string> */
    public array $expectedVersions = [];
    /** @var list<list<string>> */
    public array $rehearsalVersions = [];
    public Docker $docker;

    public function run(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $result = parent::run($arguments, $cwd, $env, $input, $timeout, false, $logPath, $interactive);
        if ($check && $result->exitCode !== 0) {
            $diagnostic = substr($result->stdout . "\n" . $result->stderr, -20000);
            foreach ($this->privateValues as $value) {
                if ($value !== '') {
                    $diagnostic = str_replace($value, '[redacted]', $diagnostic);
                }
            }
            $diagnostics = sys_get_temp_dir() . '/baander-dev-database-failure-' . bin2hex(random_bytes(8));
            PrivateDirectory::prepare($diagnostics);
            $log = $diagnostics . '/failure.log';
            AtomicFile::write($log, $diagnostic);
            throw new WorkflowError('Disposable database drill command failed; sanitized diagnostic: ' . $log);
        }
        if ($result->exitCode === 0 && in_array('start', $arguments, true) && in_array('--attach', $arguments, true)) {
            $candidate = $arguments[array_key_last($arguments)];
            if (str_starts_with($candidate, 'baander-rehearsal-') && str_contains($candidate, '-app-')) {
                $restored = substr($candidate, 0, (int) strrpos($candidate, '-app-'));
                $versions = drillLines(drillSql($this->docker, $restored, 'SELECT version FROM doctrine_migration_versions ORDER BY version'));
                drillCheck($versions === $this->expectedVersions, 'target migration was not applied in the isolated rehearsal');
                $this->rehearsalVersions[] = $versions;
            }
        }
        return $result;
    }
}

final class VerifiedDrillDatabase extends Database
{
    public bool $restoredProbe = false;
    public Docker $drillDocker;

    public function sql(array $instance, string $sql, ?string $container = null): array
    {
        $result = parent::sql($instance, $sql, $container);
        if ($container !== null && str_starts_with($container, 'baander-rehearsal-') && $sql === self::CATALOG_SQL) {
            drillCheck(drillSql($this->drillDocker, $container, "SELECT id FROM development_checkpoint_probe WHERE value &@~ 'library'") === '1');
            drillCheck(drillSql($this->drillDocker, $container, "INSERT INTO development_checkpoint_probe(value) VALUES ('next') RETURNING id") === '2');
            $this->restoredProbe = true;
        }
        return $result;
    }
}

function drillRemoveTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

try {
    Requirements::check();
    chdir(dirname(__DIR__));
    $name = 'baander-dev-database-' . time() . '-' . getmypid();
    $runner = new DatabaseDrillRunner();
    $context = $runner->run(['docker', 'context', 'show'])->stdout |> trim(...);
    $daemon = $runner->run(['docker', '--context', $context, 'info', '--format', '{{json .}}'])->stdout
        |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    $configuration = [
        'executable' => 'docker',
        'context' => $context,
        'daemon_id' => $daemon['ID'],
    ];
    $docker = new Docker($runner, $configuration);
    $runner->docker = $docker;
    $containers = [];
    $volumes = [];
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(8));
    PrivateDirectory::prepare($root);
    try {
        $store = new StateStore($root . '/state');
        $instance = [
            'id' => 'persistent',
            'kind' => 'persistent',
            'hostname' => 'checkpoint.baander.app',
            'project' => $name,
            'network' => $name,
            'docker' => $configuration,
            'writers_stopped' => true,
            'compose_file' => $root . '/compose.json',
        ];
        $release = [
            'source' => getcwd(),
            'revision' => str_repeat('d', 40),
            'app_image' => getenv('BAANDER_DATABASE_APP_IMAGE') ?: (getenv('BAANDER_TEST_IMAGE') ?: 'baander-development-app:qualification'),
            'postgres_image' => getenv('BAANDER_DATABASE_TEST_IMAGE') ?: 'baander-database:latest',
            'redis_image' => getenv('BAANDER_DATABASE_REDIS_IMAGE') ?: 'redis/redis-stack-server:edge',
        ];
        $release['nginx_image'] = $release['app_image'];
        $instance = new Bootstrap($store, $runner)->configure($instance);
        $runtime = new Runtime($store, $runner);
        $environment = $runtime->environment($instance);
        foreach ($environment as $key => $value) {
            if (preg_match('/SECRET|PASSWORD|PRIVATE_KEY/', $key) === 1) {
                $runner->privateValues[] = $value;
            }
        }
        $model = $runtime->config($instance, $release);
        $compose = [
            'name' => $name,
            'services' => array_intersect_key($model['services'], array_flip(['postgres', 'redis'])),
            'networks' => ['private' => $model['networks']['private']],
            'volumes' => array_intersect_key($model['volumes'], array_flip(['database', 'redis'])),
        ];
        AtomicFile::writeJson($instance['compose_file'], $compose);
        $database = new VerifiedDrillDatabase($store, $runner);
        $database->drillDocker = $docker;
        $migrationFiles = glob($release['source'] . '/migrations/Version*.php') ?: [];
        sort($migrationFiles, SORT_STRING);
        drillCheck(count($migrationFiles) >= 2, 'the drill needs an existing migration followed by a pending target migration');
        $runner->expectedVersions = array_map(static fn(string $path): string => 'DoctrineMigrations\\' . basename($path, '.php'), $migrationFiles);
        $sourceVersion = $runner->expectedVersions[count($migrationFiles) - 2];
        $pendingVersion = $runner->expectedVersions[count($migrationFiles) - 1];
        try {
            $docker->run(['network', 'create', '--internal', $name]);
            foreach (['database', 'redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state'] as $key) {
                $volumeName = $model['volumes'][$key]['name'];
                $docker->run(['volume', 'create', $volumeName]);
                $volumes[] = $volumeName;
            }
            $docker->compose($instance, ['up', '-d', '--wait', 'postgres', 'redis']);
            $postgres = trim($docker->compose($instance, ['ps', '-q', 'postgres'])->stdout);
            $sourceRedis = trim($docker->compose($instance, ['ps', '-q', 'redis'])->stdout);
            drillCheck($postgres !== '' && $sourceRedis !== '');
            drillCheck($database->inspect($instance, $release)['fresh']);
            drillSql($docker, $postgres, "CREATE EXTENSION IF NOT EXISTS pgroonga; CREATE TABLE development_checkpoint_probe(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO development_checkpoint_probe(value) VALUES ('retained library identity'); CREATE INDEX development_checkpoint_probe_search ON development_checkpoint_probe USING pgroonga(value);");
            try {
                $database->inspect($instance, $release);
                throw new RuntimeException('populated unversioned database was accepted');
            } catch (WorkflowError $error) {
                drillCheck(str_contains($error->getMessage(), 'unversioned'));
            }
            drillCheck(drillSql($docker, $postgres, "SELECT to_regclass('doctrine_migration_versions') IS NULL") === 't');
            drillSql($docker, $postgres, 'DROP TABLE development_checkpoint_probe');
            $migrationEnv = array_intersect_key($environment, array_flip(['DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'APP_SECRET', 'DATABASE_URL']));
            $migrationEnv += ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'];
            $migrationArguments = [];
            foreach ($migrationEnv as $key => $value) {
                array_push($migrationArguments, '--env', $key);
            }
            $docker->run(['run', '--rm', '--network', $name, '--user', '0:0', ...$migrationArguments,
                '--entrypoint', 'php', $release['app_image'], '-d', 'memory_limit=512M', 'bin/console', 'doctrine:migrations:migrate', $sourceVersion, '--no-interaction', '--env=prod'], env: $migrationEnv, timeout: null);
            drillSql($docker, $postgres, "CREATE TABLE development_checkpoint_probe(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO development_checkpoint_probe(value) VALUES ('retained library identity'); CREATE INDEX development_checkpoint_probe_search ON development_checkpoint_probe USING pgroonga(value);");
            $catalog = $database->inspect($instance, $release);
            drillCheck($catalog['versions'] === array_slice($runner->expectedVersions, 0, -1));
            drillCheck($catalog['pending'] === [$pendingVersion]);
            drillCheck((bool) $catalog['objects']);
            drillCheck($catalog['objects'] === $database->inspect($instance, $release)['objects']);
            foreach (['var', 'secrets', 'storage', 'conversions', 'transcode_state'] as $key) {
                $docker->run(['run', '--rm', '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                    '--mount', 'type=volume,src=' . $model['volumes'][$key]['name'] . ',dst=/seed', $release['app_image'], '-ec',
                    'umask 077; printf "%s" "$1" > /seed/checkpoint-probe; chown -R www-data:www-data /seed', 'seed', $key]);
            }
            $docker->run(['run', '--rm', '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                '--mount', 'type=volume,src=' . $model['volumes']['secrets']['name'] . ',dst=/seed', $release['app_image'], '-ec',
                'umask 077; mkdir -p /seed/oauth; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /seed/oauth/private.key 2>/dev/null; '
                . 'openssl pkey -in /seed/oauth/private.key -pubout -out /seed/oauth/public.key; chown -R www-data:www-data /seed']);
            drillCheck(drillLines(drillRedis($docker, $sourceRedis, 'CONFIG', 'GET', 'appendonly')) === ['appendonly', 'yes']);
            drillCheck(drillLines(drillRedis($docker, $sourceRedis, 'CONFIG', 'GET', 'dir')) === ['dir', '/data'], 'Redis persistence must use its mounted volume');
            drillCheck(drillRedis($docker, $sourceRedis, 'SET', 'checkpoint:durable', 'queue-payload') === 'OK');
            drillCheck(drillRedis($docker, $sourceRedis, 'SET', 'checkpoint:ttl', 'expiring-payload', 'PX', '3600000') === 'OK');
            $expiry = drillRedis($docker, $sourceRedis, 'PEXPIRETIME', 'checkpoint:ttl');
            drillCheck((int) $expiry > 0);
            drillCheck(drillRedis($docker, $sourceRedis, 'SET', 'checkpoint:expired', 'short-lived', 'PX', '1000') === 'OK');
            drillCheck(drillRedis($docker, $sourceRedis, 'GET', 'checkpoint:expired') === 'short-lived');
            $identityDigest = hash_file('sha256', $store->directory('persistent') . '/env.json');
            $checkpoint = $database->checkpoint($instance, $release);
            $directory = $checkpoint['directory'];
            drillCheck($checkpoint['verified'] === false);
            drillCheck($checkpoint['state'] === ['redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state']);
            drillCheck($checkpoint['catalog']['objects'] === $catalog['objects']);
            drillCheck(!json_decode($docker->run(['inspect', $sourceRedis])->stdout, true, flags: JSON_THROW_ON_ERROR)[0]['State']['Running']);
            clearstatcache();
            drillCheck((fileperms($directory) & 0777) === 0700);
            foreach (new DirectoryIterator($directory) as $artifact) {
                if (!$artifact->isDot()) {
                    drillCheck(($artifact->getPerms() & 0777) === 0600, $artifact->getFilename());
                }
            }
            drillCheck(hash_file('sha256', $directory . '/env.json') === $identityDigest);
            $originalSize = filesize($directory . '/database.dump');
            drillCheck(hash_file('sha256', $directory . '/database.dump') === $checkpoint['archive_sha256']);
            file_put_contents($directory . '/database.dump', 'corrupt', FILE_APPEND);
            try {
                $database->rehearse($instance, $release, $checkpoint);
                throw new RuntimeException('changed database archive was accepted');
            } catch (WorkflowError $error) {
                drillCheck(str_contains($error->getMessage(), 'archive changed'));
            }
            $archive = fopen($directory . '/database.dump', 'r+b');
            drillCheck($archive !== false && $originalSize !== false);
            ftruncate($archive, $originalSize);
            fclose($archive);
            try {
                $database->rehearse($instance, array_replace($release, ['revision' => str_repeat('e', 40)]), $checkpoint);
                throw new RuntimeException('wrong target release was accepted');
            } catch (WorkflowError $error) {
                drillCheck(str_contains($error->getMessage(), 'different target release'));
            }
            $checkpoint = $database->rehearse($instance, $release, $checkpoint);
            drillCheck($checkpoint['verified'] && $database->restoredProbe);
            drillCheck($runner->rehearsalVersions === [$runner->expectedVersions, $runner->expectedVersions]);
            $unchanged = $database->inspect($instance, $release);
            drillCheck($unchanged['versions'] === array_slice($runner->expectedVersions, 0, -1));
            drillCheck($unchanged['pending'] === [$pendingVersion]);
            drillCheck(AtomicFile::readJson($directory . '/checkpoint.json')['verified']);
            foreach ($checkpoint['state'] as $key) {
                $restoredVolume = $name . '-restored-' . $key;
                $docker->run(['volume', 'create', $restoredVolume]);
                $volumes[] = $restoredVolume;
                $extractor = $name . '-extract-' . $key;
                $containers[] = $extractor;
                $docker->run(['create', '--name', $extractor, '--network', 'none', '--user', '0:0', '--entrypoint', 'tar',
                    '--mount', 'type=volume,src=' . $restoredVolume . ',dst=/restore', $release['app_image'],
                    '-xpf', '/tmp/state.tar', '-C', '/restore']);
                $docker->run(['cp', $directory . '/' . $key . '.tar', $extractor . ':/tmp/state.tar']);
                $docker->run(['start', '--attach', $extractor]);
                drillCheck(trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $extractor])->stdout) === '0');
                $docker->run(['rm', $extractor]);
                $containers = array_diff($containers, [$extractor]) |> array_values(...);
                if ($key !== 'redis') {
                    $inspector = $name . '-inspect-' . $key;
                    $containers[] = $inspector;
                    $marker = $docker->run(['run', '--rm', '--name', $inspector, '--network', 'none', '--user', '0:0',
                        '--entrypoint', 'cat', '--mount', 'type=volume,src=' . $restoredVolume . ',dst=/restore,readonly',
                        $release['app_image'], '/restore/checkpoint-probe'])->stdout;
                    drillCheck($marker === $key, 'Restored volume marker differs from its writable state class.');
                    $containers = array_diff($containers, [$inspector]) |> array_values(...);
                }
                $extracted = $root . '/archive-inspection/' . $key;
                SafeTar::extract($directory . '/' . $key . '.tar', $extracted);
                if ($key === 'redis') {
                    $appendonly = false;
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extracted, FilesystemIterator::SKIP_DOTS)) as $member) {
                        $appendonly = $appendonly || str_contains($member->getPathname(), 'appendonly');
                    }
                    drillCheck($appendonly);
                } else {
                    drillCheck(is_file($extracted . '/checkpoint-probe') && file_get_contents($extracted . '/checkpoint-probe') === $key);
                }
                if ($key === 'secrets') {
                    drillCheck((fileperms($extracted . '/oauth/private.key') & 0777) === 0600);
                }
            }
            $restoredRedis = $name . '-restored-redis';
            $containers[] = $restoredRedis;
            $docker->run(['run', '-d', '--name', $restoredRedis, '--network', 'none',
                '--mount', 'type=volume,src=' . $restoredRedis . ',dst=/data', '--env', 'REDIS_PASSWORD',
                '--entrypoint', '/bin/sh', $release['redis_image'], '-ec',
                'exec redis-server --dir /data --appendonly yes --requirepass "$REDIS_PASSWORD"'], env: ['REDIS_PASSWORD' => $environment['REDIS_PASSWORD']]);
            $readyDeadline = hrtime(true) / 1_000_000_000 + 30;
            do {
                $remaining = $readyDeadline - hrtime(true) / 1_000_000_000;
                if ($remaining <= 0) {
                    throw new RuntimeException('Restored Redis did not become ready within 30 seconds.');
                }
                $ping = $docker->run(['exec', $restoredRedis, '/bin/sh', '-ec',
                    'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli PING'], timeout: min(5, $remaining), check: false);
                if ($ping->exitCode === 0 && trim($ping->stdout) === 'PONG') {
                    break;
                }
                usleep((int) (min(1, max(0, $readyDeadline - hrtime(true) / 1_000_000_000)) * 1_000_000));
            } while (true);
            drillCheck(drillRedis($docker, $restoredRedis, 'GET', 'checkpoint:durable') === 'queue-payload');
            drillCheck(drillRedis($docker, $restoredRedis, 'GET', 'checkpoint:ttl') === 'expiring-payload');
            drillCheck(drillRedis($docker, $restoredRedis, 'PEXPIRETIME', 'checkpoint:ttl') === $expiry);
            drillCheck(drillRedis($docker, $restoredRedis, 'EXISTS', 'checkpoint:expired') === '0');
            drillCheck(!json_decode($docker->run(['inspect', $sourceRedis])->stdout, true, flags: JSON_THROW_ON_ERROR)[0]['State']['Running']);
            drillCheck(drillSql($docker, $postgres, 'SELECT count(*) FROM development_checkpoint_probe') === '1');
            drillCheck(hash_file('sha256', $store->directory('persistent') . '/env.json') === $identityDigest);
            drillSql($docker, $postgres, "INSERT INTO doctrine_migration_versions(version) VALUES ('DoctrineMigrations\\Version19990101000000')");
            try {
                $database->inspect($instance, $release);
                throw new RuntimeException('former migration was accepted');
            } catch (WorkflowError $error) {
                drillCheck(str_contains($error->getMessage(), 'migration history'));
            }
            echo "PASS: actual checkpoint and isolated pending migration rehearsal plus idempotent second pass; unchanged source migration history; deterministic catalogs, archive integrity, PGroonga and sequence restore; private identity and six state archives; stopped Redis AOF restore with unchanged expiry and expired-key removal.\n";
        } finally {
            foreach ($containers as $container) {
                $docker->run(['rm', '--force', '--volumes', $container], check: false);
            }
            $docker->compose($instance, ['down', '--volumes'], check: false);
            foreach ($volumes as $volumeName) {
                $docker->run(['volume', 'rm', $volumeName], check: false);
            }
            $docker->run(['network', 'rm', $name], check: false);
        }
    } finally {
        drillRemoveTree($root);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
