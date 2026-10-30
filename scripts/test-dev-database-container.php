#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Tooling\Development\Bootstrap;
use Baander\Tooling\Development\Database;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CommandFailure;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Inventory\SafeTar;
use Baander\Tooling\Resources\{ResourceLedger, ResourceKind, DisposableContainer};
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

function drillSql(Client $docker, string $container, string $statement): string
{
    return $docker->run(
        [
            'exec', '-i', $container, '/bin/sh', '-ec',
            'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -X -qAt --set=ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
        ],
        input: $statement,
    )->stdout |> trim(...);
}

function drillRedis(Client $docker, string $container, string ...$arguments): string
{
    return $docker->run([
        'exec', $container, '/bin/sh', '-ec',
        'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli --raw "$@"',
        'redis-check', ...$arguments,
    ])->stdout |> trim(...);
}

/** @param array<string,mixed> $model */
function drillCreateService(ResourceLedger $ledger, Client $docker, array $model, string $service, string $project, string $network): string
{
    $definition = $model['services'][$service];
    $arguments = ['--network', $network, '--network-alias', $service === 'postgres' ? 'database' : $service,
        '--label', 'com.docker.compose.project=' . $project, '--label', 'com.docker.compose.service=' . $service];
    foreach ($definition['environment'] as $key => $_) {
        array_push($arguments, '--env', $key);
    }
    foreach ($definition['volumes'] as $mount) {
        array_push($arguments, '--mount', 'type=volume,src=' . $model['volumes'][$mount['source']]['name'] . ',dst=' . $mount['target']);
    }
    $environment = array_map(static fn(string $value): string => str_replace('$$', '$', $value), $definition['environment']);
    $command = array_map(static fn(string $value): string => str_replace('$$', '$', $value), $definition['command'] ?? []);
    $name = $project . '-' . $service;
    $ledger->create(ResourceKind::Container, $name, [...$arguments, $definition['image'], ...$command], $environment);
    $docker->run(['start', $name]);
    return $name;
}

final class DatabaseDrillExecutor implements CommandExecutor
{
    /** @var list<string> */
    public array $privateValues = [];
    /** @var list<string> */
    public array $expectedVersions = [];
    /** @var list<list<string>> */
    public array $rehearsalVersions = [];
    public Client $docker;

    /** @var \WeakMap<RunningProcess,ProcessSpec> */
    private \WeakMap $commands;

    public function __construct(private readonly CommandExecutor $executor)
    {
        $this->commands = new \WeakMap();
    }

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        try {
            $result = $this->executor->run($command, $policy);
        } catch (CommandFailure $error) {
            $this->failure($error);
        }
        return $this->observe($command, $result);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        try {
            $process = $this->executor->start($command);
        } catch (CommandFailure $error) {
            $this->failure($error);
        }
        $this->commands[$process] = $command;
        return $process;
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        try {
            $result = $this->executor->wait($process, $policy);
            return $this->observe($this->commands[$process], $result);
        } catch (CommandFailure $error) {
            $this->failure($error);
        } finally {
            unset($this->commands[$process]);
        }
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        try {
            return $this->executor->stop($process, $signal);
        } catch (CommandFailure $error) {
            $this->failure($error);
        } finally {
            unset($this->commands[$process]);
        }
    }

    private function redact(string $value): string
    {
        foreach ($this->privateValues as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }
        return $value;
    }

    private function failure(CommandFailure $error): never
    {
        $result = $error->result;
        $sanitized = $result === null ? null : new ProcessResult(
            $result->exitCode,
            $result->signal,
            $result->timedOut,
            $result->cancelled,
            $this->redact($result->stdout),
            $this->redact($result->stderr),
            $result->duration,
            $result->requestedSignal,
        );
        $diagnostics = sys_get_temp_dir() . '/baander-dev-database-failure-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($diagnostics);
        $log = $diagnostics . '/failure.log';
        AtomicFile::write($log, $this->redact(substr($error->getMessage() . "\n" . ($result->stdout ?? '') . "\n" . ($result->stderr ?? ''), -20000)));
        throw new CommandFailure('Disposable database drill command failed; sanitized diagnostic: ' . $log, $error->getCode(), $sanitized);
    }

    private function observe(ProcessSpec $command, ProcessResult $result): ProcessResult
    {
        $arguments = $command->argv;
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
    public Client $drillDocker;

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
    $invocationEnvironment = getenv();
    $commandEnvironment = Environment::clean($invocationEnvironment);
    $tooling = ToolingConfiguration::resolve(environment: $invocationEnvironment);
    $runner = new DatabaseDrillExecutor(new EngineExecutor(ExecutionContext::bounded($tooling->budgets->seconds('backend'), $commandEnvironment)));
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(8));
    PrivateDirectory::prepare($root);
    try {
        $target = new TargetResolver($runner, $invocationEnvironment)->resolve($tooling->docker, $root . '/docker-transport');
        $configuration = $target->toArray();
        $docker = new Client($runner, $target, $commandEnvironment);
        $docker->requireCompose();
        $runner->docker = $docker;
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
        $applicationReference = ($invocationEnvironment['BAANDER_DATABASE_APP_IMAGE'] ?? '') ?: (($invocationEnvironment['BAANDER_TEST_IMAGE'] ?? '') ?: 'baander-development-app:qualification');
        $imageReferences = [
            'app_image' => $applicationReference, 'nginx_image' => $applicationReference,
            'worker_image' => $applicationReference, 'operator_image' => $applicationReference, 'tool_image' => $applicationReference,
            'postgres_image' => ($invocationEnvironment['BAANDER_DATABASE_TEST_IMAGE'] ?? '') ?: $tooling->images->role('postgres')->reference,
            'redis_image' => ($invocationEnvironment['BAANDER_DATABASE_REDIS_IMAGE'] ?? '') ?: $tooling->images->role('fixtureRedis')->reference,
        ];
        $release = ['source' => getcwd(), 'revision' => str_repeat('d', 40), 'images' => []];
        $imageMetadata = [];
        foreach ($imageReferences as $role => $reference) {
            $imageMetadata[$reference] ??= json_decode($docker->run(['image', 'inspect', $reference])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            drillCheck(is_string($imageMetadata[$reference]['Id'] ?? null) && $imageMetadata[$reference]['Id'] !== '', 'Selected drill image has no immutable identity.');
            $release['images'][$role] = ['reference' => $reference, 'resolvedId' => $imageMetadata[$reference]['Id'],
                'capabilities' => [], 'daemonId' => $target->daemonId];
        }
        $release['layout'] = ApplicationLayout::fromImage($imageMetadata[$applicationReference])->toArray();
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
        $ledger = new ResourceLedger($root . '/drill-resources.json', $docker, $commandEnvironment);
        $ledger->scoped(function () use (
            $docker,
            $ledger,
            $name,
            $model,
            $database,
            $instance,
            $release,
            $sourceVersion,
            $environment,
            $store,
            $runner,
            $pendingVersion,
            $root,
            $tooling,
            $commandEnvironment
        ): void {
            $ledger->create(ResourceKind::Network, $name, ['--internal']);
            foreach (['database', 'redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state'] as $key) {
                $ledger->create(ResourceKind::Volume, $model['volumes'][$key]['name'], []);
            }
            $postgres = drillCreateService($ledger, $docker, $model, 'postgres', $name, $name);
            $sourceRedis = drillCreateService($ledger, $docker, $model, 'redis', $name, $name);
            $docker->run(['exec', $postgres, '/bin/sh', '-ec',
                'for n in $(seq 1 60); do pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" && exit 0; sleep 1; done; exit 1'], timeout: 70);
            $docker->run(['exec', $sourceRedis, '/bin/sh', '-ec',
                'export REDISCLI_AUTH="$REDIS_PASSWORD"; for n in $(seq 1 60); do redis-cli ping | grep -qx PONG && exit 0; sleep 1; done; exit 1'], timeout: 70);
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
            DisposableContainer::run($docker, $root . '/source-migration-resources.json', ['--network', $name, '--user', '0:0', ...$migrationArguments,
                '--entrypoint', 'php', $release['images']['app_image']['resolvedId'], '-d', 'memory_limit=512M', 'bin/console', 'doctrine:migrations:migrate', $sourceVersion, '--no-interaction', '--env=prod'], $commandEnvironment, $tooling->budgets->seconds('cleanup'), timeout: null, containerEnvironment: $migrationEnv);
            drillSql($docker, $postgres, "CREATE TABLE development_checkpoint_probe(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO development_checkpoint_probe(value) VALUES ('retained library identity'); CREATE INDEX development_checkpoint_probe_search ON development_checkpoint_probe USING pgroonga(value);");
            $catalog = $database->inspect($instance, $release);
            drillCheck($catalog['versions'] === array_slice($runner->expectedVersions, 0, -1));
            drillCheck($catalog['pending'] === [$pendingVersion]);
            drillCheck((bool) $catalog['objects']);
            drillCheck($catalog['objects'] === $database->inspect($instance, $release)['objects']);
            foreach (['var', 'secrets', 'storage', 'conversions', 'transcode_state'] as $key) {
                DisposableContainer::run($docker, $root . '/seed-resources.json', ['--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                    '--mount', 'type=volume,src=' . $model['volumes'][$key]['name'] . ',dst=/seed', $release['images']['app_image']['resolvedId'], '-ec',
                    'umask 077; printf "%s" "$1" > /seed/checkpoint-probe; chown -R www-data:www-data /seed', 'seed', $key], $commandEnvironment, $tooling->budgets->seconds('cleanup'));
            }
            DisposableContainer::run($docker, $root . '/seed-resources.json', ['--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                '--mount', 'type=volume,src=' . $model['volumes']['secrets']['name'] . ',dst=/seed', $release['images']['app_image']['resolvedId'], '-ec',
                'umask 077; mkdir -p /seed/oauth; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /seed/oauth/private.key 2>/dev/null; '
                . 'openssl pkey -in /seed/oauth/private.key -pubout -out /seed/oauth/public.key; chown -R www-data:www-data /seed'], $commandEnvironment, $tooling->budgets->seconds('cleanup'));
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
                $ledger->create(ResourceKind::Volume, $restoredVolume, []);
                $extractor = $name . '-extract-' . $key;
                $ledger->create(ResourceKind::Container, $extractor, [ '--network', 'none', '--user', '0:0', '--entrypoint', 'tar',
                    '--mount', 'type=volume,src=' . $restoredVolume . ',dst=/restore', $release['images']['app_image']['resolvedId'],
                    '-xpf', '/tmp/state.tar', '-C', '/restore']);
                $docker->run(['cp', $directory . '/' . $key . '.tar', $extractor . ':/tmp/state.tar']);
                $docker->run(['start', '--attach', $extractor]);
                drillCheck(trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $extractor])->stdout) === '0');
                if ($key !== 'redis') {
                    $inspector = $name . '-inspect-' . $key;
                    $marker = DisposableContainer::run($docker, $root . '/inspect-resources.json', [ '--network', 'none', '--user', '0:0',
                        '--entrypoint', 'cat', '--mount', 'type=volume,src=' . $restoredVolume . ',dst=/restore,readonly',
                        $release['images']['app_image']['resolvedId'], '/restore/checkpoint-probe'], $commandEnvironment, $tooling->budgets->seconds('cleanup'))->stdout;
                    drillCheck($marker === $key, 'Restored volume marker differs from its writable state class.');
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
            $ledger->create(ResourceKind::Container, $restoredRedis, [ '--network', 'none',
                '--mount', 'type=volume,src=' . $restoredRedis . ',dst=/data', '--env', 'REDIS_PASSWORD',
                '--entrypoint', '/bin/sh', $release['images']['redis_image']['resolvedId'], '-ec',
                'exec redis-server --dir /data --appendonly yes --requirepass "$REDIS_PASSWORD"'], ['REDIS_PASSWORD' => $environment['REDIS_PASSWORD']]);
            $docker->run(['start', $restoredRedis]);
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
        }, $tooling->budgets->seconds('cleanup'));
    } finally {
        $retainedReceipt = false;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                $data = json_decode(file_get_contents($file->getPathname()), true);
                if (is_array($data) && isset($data['token'], $data['resources'], $data['target'])) {
                    $retainedReceipt = true;
                    break;
                }
            }
        }
        if (!$retainedReceipt) {
            drillRemoveTree($root);
        } else {
            fwrite(STDERR, 'Disposable database drill retains unresolved ownership and archives: ' . $root . "\n");
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() >= 1 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
