<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Development\Database;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Process\{EngineExecutor, CompletionPolicy, ProcessSpec};
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Filesystem\AtomicFile;

final class Qualification
{
    private string $source;
    public readonly string $workspace;
    private StateStore $store;
    private string $checkout;
    private string $remote;
    private string $media;
    private string $logs;
    /** @var list<array<string,mixed>> */
    private array $results = [];
    /** @var array<string,list<string>> */
    private array $baseline = [];
    private Database $database;
    private Commands $commands;
    private bool $ownsWorkspace = false;
    private string $originalDockerfile = '';
    private string $initial = '';
    /** @var array<string,string> */
    private array $seedMediaHashes = [];

    /** @param array<string,string|bool> $args
     * @param array<string,string> $environment
     */
    public function __construct(private readonly array $args, private readonly EngineExecutor $executor, private readonly Client $selectedDocker, private readonly array $environment)
    {
        $this->source = dirname(__DIR__, 5);
        $this->workspace = Support::path((string) $args['workspace']);
        $this->store = new StateStore(Support::path((string) $args['state-root']));
        $this->checkout = $this->workspace . '/checkout';
        $this->remote = $this->workspace . '/origin.git';
        $this->media = $this->workspace . '/media';
        $this->logs = $this->workspace . '/logs';
        $this->database = new Database($this->store, $this->executor);
        $this->commands = new Commands($this->checkout, $this->logs, $this->executor, $this->environment);
    }

    /** @param non-empty-list<string> $argv */
    private function command(array $argv, ?string $cwd = null, int $expected = 0): string
    {
        return $this->commands->run($argv, $cwd, $expected);
    }

    /** @param list<string> $arguments
     * @return non-empty-list<string>
     */
    private function cliArguments(string $command, array $arguments = []): array
    {
        return [
            PHP_BINARY,
            $this->source . '/scripts/dev-environment.php',
            '--state-root',
            $this->store->root,
            $command,
            ...$arguments,
        ];
    }

    /** @param list<string> $arguments */
    private function cli(
        string $command,
        array $arguments = [],
        ?string $checkout = null,
        int $expected = 0,
    ): string {
        return $this->command($this->cliArguments($command, $arguments), $checkout, $expected);
    }

    /** @return array<string,mixed> */
    private function state(string $identity = 'persistent'): array
    {
        return $this->store->load($identity) ?? [];
    }

    private function poll(callable $predicate, string $description, ?float $timeout = null): void
    {
        $deadline = Support::now() + ($timeout ?? (int) $this->args['timeout']);
        do {
            if ($predicate()) {
                return;
            }
            $this->commands->pause(500000);
        } while (Support::now() < $deadline);
        throw new \RuntimeException('Timed out waiting for ' . $description . '; inspect ' . $this->store->root);
    }

    private function docker(string $identity = 'persistent'): Client
    {
        return new Client($this->executor, Target::fromArray($this->state($identity)['docker']), $this->environment);
    }

    private function ready(string $revision, string $identity = 'persistent'): void
    {
        $this->poll(function () use ($revision, $identity): bool {
            $state = $this->state($identity);
            return ($state['running_revision'] ?? null) === $revision
                && ($state['phase'] ?? null) === 'ready'
                && ($state['readiness']['workers_ready'] ?? false);
        }, $identity . ' ready at ' . $revision);
        $this->origin($identity);
        $instance = $this->state($identity);
        $docker = $this->docker($identity);
        $identifier = trim($docker->compose($instance['project'], $instance['compose_file'], ['ps', '-q', 'app'])->stdout);
        $actual = trim($docker->run(['inspect', '--format', '{{.Image}}', $identifier])->stdout);
        Support::check(
            $actual === $instance['release']['images']['app_image']['resolvedId'],
            'App did not run the prepared immutable image',
        );
        if ($identity === 'persistent') {
            $label = trim($docker->run([
                'image',
                'inspect',
                '--format',
                '{{index .Config.Labels "org.opencontainers.image.revision"}}',
                $actual,
            ])->stdout);
            Support::check($label === $revision, 'Serving image label does not match selected master SHA');
        }
    }

    private function origin(string $identity = 'persistent'): void
    {
        $this->command([
            'curl',
            '--fail',
            '--silent',
            '--show-error',
            '--max-time',
            '20',
            'https://' . $this->state($identity)['hostname'] . '/ready',
        ]);
    }

    /** @return array<string,mixed> */
    private function sql(string $sql, string $identity = 'persistent'): array
    {
        return $this->database->sql($this->state($identity), $sql);
    }

    /** @return array<string,mixed> */
    private function fingerprint(string $identity = 'persistent'): array
    {
        $data = $this->sql(
            "BEGIN READ ONLY; SELECT json_build_object('users',"
            . "(SELECT md5(coalesce(json_agg(row(id,email,password) ORDER BY id)::text,'')) FROM users),"
            . "'libraries',(SELECT md5(coalesce(json_agg(row(id,slug,path) ORDER BY id)::text,'')) FROM libraries),"
            . "'clients',(SELECT md5(coalesce(json_agg(row(id,public_id) ORDER BY id)::text,'')) FROM oauth_clients)); COMMIT;",
            $identity,
        );
        $data['environment'] = hash_file('sha256', $this->store->directory($identity) . '/env.json');
        return $data;
    }

    /** @param array<string,mixed> $evidence */
    private function record(string $scenario, array $evidence = []): void
    {
        $this->results[] = ['scenario' => $scenario, ...$evidence];
        AtomicFile::writeJson($this->workspace . '/evidence.json', $this->results);
        echo 'PASS: ' . $scenario . "\n";
    }

    /** @return array<string,string> */
    private function mediaHashes(): array
    {
        $hashes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->media,
            \FilesystemIterator::SKIP_DOTS,
        )) as $path) {
            if ($path->isFile()) {
                $hashes[substr($path->getPathname(), strlen($this->media) + 1)] = hash_file('sha256', $path->getPathname());
            }
        }
        ksort($hashes);
        return $hashes;
    }

    /** @return array<string,list<string>> */
    private function inventory(): array
    {
        $inventory = [];
        foreach ([
            'containers' => ['ps', '-aq', '--no-trunc'],
            'networks' => ['network', 'ls', '-q', '--no-trunc'],
            'volumes' => ['volume', 'ls', '-q'],
        ] as $kind => $arguments) {
            $inventory[$kind] = preg_split(
                '/\s+/',
                trim($this->selectedDocker->run($arguments)->stdout),
                flags: PREG_SPLIT_NO_EMPTY,
            );
            sort($inventory[$kind]);
        }
        return $inventory;
    }

    public function preserveFailure(): void
    {
        if (!$this->ownsWorkspace) {
            return;
        }
        if ($this->store->load('persistent') !== null) {
            $this->store->setEnabled('persistent', false);
            $this->executor->run(new ProcessSpec(
                [
                    'systemctl',
                    '--user',
                    'disable',
                    '--now',
                    'baander-development-' . $this->store->scope() . '.timer',
                ],
                environment: $this->environment,
                replaceEnvironment: true,
                processGroup: true,
            ), new CompletionPolicy(null));
        }
        $join = fn(string $command): string => $this->cliArguments(
            $command,
            ['--instance', 'persistent', ...($command === 'status' ? ['--json'] : [])],
        )
            |> (static fn(array $arguments): array => array_map(escapeshellarg(...), $arguments))
            |> (static fn(array $arguments): string => implode(' ', $arguments));
        $context = [
            'workspace' => $this->workspace,
            'state_root' => $this->store->root,
            'checkout' => $this->checkout,
            'timer_disabled' => true,
            'status' => $join('status'),
            'resume' => $join('up'),
            'recovery' => $join('recover'),
        ];
        AtomicFile::writeJson($this->workspace . '/failure-context.json', $context);
        fwrite(
            STDERR,
            'Scoped timer disabled; in-flight work and owned resources retained.'
            . "\nInspect from " . $this->checkout . ': ' . $context['status']
            . "\nResume after diagnosis: " . $context['resume']
            . "\nBlocked mutation requires explicit reconciliation before: " . $context['recovery'] . "\n",
        );
    }

    private function cleanup(): void
    {
        $instances = [];
        foreach (glob($this->store->root . '/*/state.json') ?: [] as $path) {
            $identity = basename(dirname($path));
            if ($identity === 'persistent' || str_starts_with($identity, 'preview-')) {
                $instances[] = AtomicFile::readJson($path);
            }
        }
        $projects = array_column($instances, 'project');
        $expectedVolumes = [];
        $expectedNetworks = [];
        foreach ($instances as $instance) {
            Support::check(
                $instance['repository'] === $this->remote,
                'Cleanup instance belongs to another repository.',
            );
            $docker = new Client($this->executor, Target::fromArray($instance['docker']), $this->environment);
            $docker->validateIdentity();
            $this->cli('down', ['--instance', $instance['id']]);
            $compose = AtomicFile::readJson($instance['compose_file']);
            foreach ($compose['volumes'] as $volume) {
                $expectedVolumes[] = $volume['name'];
            }
            foreach (glob($this->store->directory($instance['id']) . '/workers/*/manifest.json') ?: [] as $path) {
                $manifest = AtomicFile::readJson($path);
                Support::check(
                    $manifest['namespace'] === $instance['project'],
                    'Worker namespace is not owned.',
                );
                $expectedVolumes[] = $manifest['ipcVolume'];
            }
            $docker->compose($instance['project'], $instance['compose_file'], ['down', '--remove-orphans']);
            $network = $instance['network'];
            $expectedNetworks[is_array($network) ? $network['name'] : $network] = $instance['id'];
        }
        $gateway = AtomicFile::readJson($this->store->root . '/gateway/state.json');
        $docker = new Client($this->executor, Target::fromArray($gateway['docker']), $this->environment);
        $docker->compose($gateway['project'], $gateway['compose_file'], ['down', '--remove-orphans']);
        $projects[] = $gateway['project'];
        $expectedNetworks[$gateway['gateway']['name']] = 'gateway-' . $this->store->scope();
        foreach (array_unique($projects) as $project) {
            $ids = preg_split(
                '/\s+/',
                trim($docker->run([
                    'ps',
                    '-aq',
                    '--no-trunc',
                    '--filter',
                    'label=app.baander.worker.namespace=' . $project,
                ])->stdout),
                flags: PREG_SPLIT_NO_EMPTY,
            );
            foreach ($ids as $identifier) {
                Support::check(
                    !in_array($identifier, $this->baseline['containers'], true),
                    'Cleanup would remove a baseline container.',
                );
                $docker->run(['rm', $identifier]);
            }
        }
        $expectedVolumes = array_unique($expectedVolumes);
        sort($expectedVolumes);
        foreach ($expectedVolumes as $name) {
            $owned = false;
            foreach ($projects as $project) {
                $owned = $owned || str_starts_with($name, $project . '-');
            }
            Support::check(
                !in_array($name, $this->baseline['volumes'], true) && $owned,
                'Cleanup volume is not owned by qualification.',
            );
            if ($docker->run(['volume', 'inspect', $name], check: false)->exitCode === 0) {
                $docker->run(['volume', 'rm', $name]);
            }
        }
        foreach ($expectedNetworks as $name => $owner) {
            $observed = json_decode(
                $docker->run(['network', 'inspect', $name])->stdout,
                true,
                flags: JSON_THROW_ON_ERROR,
            )[0];
            Support::check(
                !in_array($observed['Id'], $this->baseline['networks'], true),
                'Cleanup would remove a baseline network.',
            );
            Support::check(
                ($observed['Labels']['app.baander.development.owner'] ?? null) === $owner,
                'Cleanup network owner changed.',
            );
            $docker->run(['network', 'rm', $name]);
        }
        $unit = 'baander-development-' . $this->store->scope();
        $this->command(['systemctl', '--user', 'disable', '--now', $unit . '.timer']);
        $config = (($this->environment['XDG_CONFIG_HOME'] ?? '') ?: ($this->environment['HOME'] ?? '') . '/.config') . '/systemd/user';
        foreach (['.service', '.timer'] as $suffix) {
            if (file_exists($config . '/' . $unit . $suffix)) {
                unlink($config . '/' . $unit . $suffix);
            }
        }
        $this->command(['systemctl', '--user', 'daemon-reload']);
        $remaining = $this->inventory();
        foreach ($this->baseline as $kind => $items) {
            Support::check(
                array_diff($items, $remaining[$kind]) === [],
                'Unrelated Docker ' . $kind . ' changed',
            );
        }
        foreach ($instances as $instance) {
            Support::check(
                trim($docker->run([
                    'ps',
                    '-aq',
                    '--filter',
                    'label=com.docker.compose.project=' . $instance['project'],
                ])->stdout) === '',
                'Owned Compose containers remain.',
            );
            Support::check(
                trim($docker->run([
                    'ps',
                    '-aq',
                    '--filter',
                    'label=app.baander.worker.namespace=' . $instance['project'],
                ])->stdout) === '',
                'Owned worker containers remain.',
            );
        }
        $this->record('owned instances, workers, gateway, volumes, networks and timer cleaned; unrelated inventories preserved');
    }

    private function commit(string $label, int $delay = 0, bool $brokenBuild = false): string
    {
        $content = $this->originalDockerfile;
        $stage = 'FROM node:22-bookworm-slim AS dev-tools';
        if ($delay !== 0) {
            $content = str_replace($stage, $stage . "\nRUN sleep " . $delay . ' && echo ' . $label, $content);
        }
        if ($brokenBuild) {
            $content = str_replace($stage, $stage . "\nRUN exit 73", $content);
        }
        file_put_contents($this->checkout . '/Dockerfile', $content);
        file_put_contents($this->checkout . '/public/workflow-qualification.txt', $label . "\n");
        $this->command(['git', 'add', 'Dockerfile', 'public/workflow-qualification.txt']);
        $this->command(['git', 'commit', '-m', 'Qualify ' . $label]);
        $revision = trim($this->command(['git', 'rev-parse', 'HEAD']));
        $this->command(['git', 'push', 'origin', 'HEAD:master']);
        return $revision;
    }

    private function seed(): void
    {
        Support::check(
            $this->store->root !== Support::path((string) $this->args['host-state-root']),
            'Qualification state must be separate from the existing trusted host state.',
        );
        Support::check(
            !file_exists($this->workspace) && $this->store->load('persistent') === null,
            'Select a new workspace and dedicated external state root without instances.',
        );
        if (is_dir($this->store->root)) {
            foreach (new \FilesystemIterator($this->store->root) as $path) {
                Support::check(
                    in_array($path->getFilename(), ['gateway', 'host-setup'], true),
                    'Qualification state may contain only preinstalled host certificates and instructions.',
                );
            }
        }
        Support::check(
            !file_exists($this->store->root . '/gateway/state.json'),
            'Qualification gateway must not already own runtime resources.',
        );
        Support::check(
            !self::within($this->store->root, $this->workspace) && !self::within($this->workspace, $this->source),
            'Workspace must be outside the checkout; state root must be outside workspace.',
        );
        mkdir($this->logs, 0700, true);
        chmod($this->workspace, 0700);
        AtomicFile::write(
            $this->workspace . '/disposable-qualification',
            "Owned workflow qualification resources only.\n",
        );
        $this->ownsWorkspace = true;
        $this->baseline = $this->inventory();
        AtomicFile::writeJson($this->workspace . '/docker-inventory-before.json', $this->baseline);
        mkdir($this->media, 0700);
        $this->command([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:sample_rate=48000:duration=8',
            '-metadata',
            'title=Workflow qualification tone',
            '-metadata',
            'artist=Baander qualification',
            '-metadata',
            'album=Development workflow',
            '-c:a',
            'flac',
            $this->media . '/workflow-qualification-tone.flac',
        ], $this->workspace);
        $this->seedMediaHashes = $this->mediaHashes();
        $this->command(
            ['git', 'clone', '--bare', '--no-hardlinks', $this->source, $this->remote],
            $this->workspace,
        );
        $this->command(['git', 'clone', $this->remote, $this->checkout], $this->workspace);
        $this->command(['git', 'checkout', '-B', 'master']);
        $this->command(['git', 'config', 'user.name', 'Baander Workflow Qualification']);
        $this->command(['git', 'config', 'user.email', 'workflow@baander.app']);
        $this->command(['git', 'push', '--force', 'origin', 'HEAD:master']);
        $this->originalDockerfile = (string) file_get_contents($this->checkout . '/Dockerfile');
        $this->initial = trim($this->command(['git', 'rev-parse', 'HEAD']));
        foreach (['gateway/tls/cert.pem', 'gateway/tls/key.pem', 'host-setup/ca/rootCA.pem'] as $relative) {
            $source = Support::path((string) $this->args['host-state-root']) . '/' . $relative;
            $target = $this->store->root . '/' . $relative;
            Support::check(is_file($source), 'Complete trusted host setup first; missing ' . $source);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            Support::check(
                copy($source, $target) && chmod($target, 0600),
                'Cannot copy trusted host identity.',
            );
        }
        if ($this->args['prepare-shared-paths']) {
            $this->command([
                (string) $this->args['prepare-shared-paths'],
                $this->workspace,
                $this->store->root,
            ]);
        } else {
            $this->cli('host-setup', ['--share-path', $this->workspace]);
        }
    }

    private static function within(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/') . '/');
    }

    private function up(?string $checkout = null): void
    {
        $arguments = ['--media', $this->media . '=/qualification-media'];
        if ($checkout === null) {
            $arguments = [...$arguments, '--instance', 'persistent'];
        }
        $this->cli('up', $arguments, $checkout);
    }

    public function run(): void
    {
        $this->seed();
        $this->up();
        $this->ready($this->initial);
        $this->cli('exec', [
            '--instance',
            'persistent',
            '--',
            'php',
            'bin/console',
            'app:library:create',
            'Qualification music',
            '/qualification-media',
            'music',
            '--slug',
            'workflow-qualification',
            '--no-interaction',
        ]);
        $original = $this->fingerprint();
        $this->up();
        Support::check(
            $this->fingerprint() === $original,
            'Repeat startup changed account/library/identity state',
        );
        $this->record(
            'fresh and repeat startup retain configured account, library and secrets',
            ['revision' => $this->initial],
        );
        $first = $this->commit('concurrent-first', delay: 35);
        $this->poll(
            fn(): bool => ($this->state()['target_revision'] ?? null) === $first
                && ($this->state()['phase'] ?? null) === 'preparing',
            'first timer preparation',
        );
        $second = $this->commit('concurrent-second');
        $this->ready($first);
        $this->ready($second);
        Support::check($this->fingerprint() === $original, 'Concurrent updates changed retained state.');
        $this->record(
            'user timer serializes successive immutable remote master releases',
            ['revisions' => [$first, $second]],
        );
        $bad = $this->commit('broken-build', brokenBuild: true);
        $this->poll(fn(): bool => ($this->state()['failed_revision'] ?? null) === $bad, 'build failure');
        Support::check(
            $this->state()['running_revision'] === $second,
            'Build failure changed serving revision.',
        );
        $this->origin();
        Support::check($this->fingerprint() === $original, 'Build failure changed retained state.');
        $this->record(
            'build failure preserves serving release and existing state',
            ['failed_revision' => $bad],
        );
        $good = $this->commit('after-build-failure');
        $this->ready($good);
        $migrations = glob($this->checkout . '/migrations/Version*.php') ?: [];
        sort($migrations);
        Support::check($migrations !== [], 'No migration exists for history refusal qualification.');
        file_put_contents($migrations[0], "\n// Disposable incompatible history.\n", FILE_APPEND);
        $this->command(['git', 'add', 'migrations']);
        $badSchema = $this->commit('incompatible-schema');
        $this->poll(
            fn(): bool => ($this->state()['failed_revision'] ?? null) === $badSchema,
            'history refusal',
        );
        Support::check(
            $this->state()['running_revision'] === $good && $this->state()['phase'] === 'ready',
            'Schema refusal changed serving release.',
        );
        $this->origin();
        Support::check($this->fingerprint() === $original, 'Schema refusal changed retained state.');
        $this->record(
            'incompatible migration history refuses before data mutation',
            ['failed_revision' => $badSchema],
        );
        $this->command(['git', 'checkout', $this->initial, '--', 'migrations']);
        $this->command(['git', 'add', 'migrations']);
        $good = $this->commit('after-schema-refusal');
        $this->ready($good);
        $previews = [];
        foreach ([1, 2] as $number) {
            $checkout = $this->workspace . '/preview-' . $number;
            $this->command(['git', 'clone', $this->remote, $checkout]);
            $this->command(['git', 'checkout', 'master'], $checkout);
            $identity = Environment::previewId($checkout, $this->remote);
            $this->up($checkout);
            Support::check($this->state($identity)['phase'] === 'ready', 'Preview did not become ready.');
            $this->origin($identity);
            $previews[] = [$checkout, $identity];
        }
        $projects = [$this->state()['project']];
        $before = [];
        foreach ($previews as [, $identity]) {
            $projects[] = $this->state($identity)['project'];
            $before[$identity] = $this->fingerprint($identity);
        }
        Support::check(count(array_unique($projects)) === 3, 'Preview projects overlap.');
        [$checkout, $identity] = $previews[0];
        $this->cli('exec', [
            '--',
            'php',
            'bin/console',
            'app:library:create',
            'Isolated qualification',
            '/qualification-media',
            'music',
            '--slug',
            'isolated-qualification',
            '--no-interaction',
        ], $checkout);
        $this->cli(
            'exec',
            ['--', 'php', 'bin/console', 'app:library:scan', 'isolated-qualification', '--no-interaction'],
            $checkout,
        );
        Support::check(
            $this->fingerprint($previews[1][1]) === $before[$previews[1][1]] && $this->fingerprint() === $original,
            'Preview mutation escaped its instance.',
        );
        $retained = $this->fingerprint($identity);
        $this->cli('down', checkout: $checkout);
        $this->origin($previews[1][1]);
        $this->origin();
        $this->up($checkout);
        Support::check($this->fingerprint($identity) === $retained, 'Preview resume changed retained state.');
        Support::check($this->mediaHashes() === $this->seedMediaHashes, 'Preview changed source media.');
        $this->record(
            'two simultaneous previews isolate scans, accounts, resources and retained resume state',
            ['previews' => array_column($previews, 1)],
        );
        Support::check(
            (bool) $this->args['media-check'],
            'Full qualification requires --media-check helper; playback cannot be skipped',
        );
        $this->command([
            PHP_BINARY,
            (string) $this->args['media-check'],
            '--state-root',
            $this->store->root,
            '--workspace',
            $this->workspace,
            '--instance',
            'persistent',
        ]);
        $this->record('queued ingestion and original plus transcoded playback');
        $original = $this->fingerprint();
        $originalMedia = $this->mediaHashes();
        if ($this->args['browser-check']) {
            $this->cli(
                'exec',
                [
                    '--',
                    'php',
                    'bin/console',
                    'app:settings:set',
                    'transcode.enabled',
                    'true',
                    '--no-interaction',
                ],
                $previews[0][0],
            );
            foreach ([[$previews[0][1], 'preview'], ['persistent', 'persistent']] as [$identity, $name]) {
                $arguments = [
                    'node',
                    (string) $this->args['browser-check'],
                    '--url',
                    'https://' . $this->state($identity)['hostname'],
                    '--output',
                    $this->workspace . '/' . $name . '-browser-evidence.json',
                ];
                if ($identity !== 'persistent') {
                    $arguments[] = '--preview';
                }
                $this->command($arguments);
            }
            $this->record('trusted HTTPS browser login and media playback on persistent instance and isolated preview');
        }
        $target = $this->commit('down-during-preparation', delay: 35);
        $this->poll(
            fn(): bool => ($this->state()['target_revision'] ?? null) === $target
                && ($this->state()['phase'] ?? null) === 'preparing',
            'preparation before down',
        );
        $this->cli('down', ['--instance', 'persistent']);
        Support::check(
            $this->state()['phase'] === 'stopped' && !$this->store->enabled('persistent'),
            'Down did not persist stopped intent.',
        );
        $target = $this->commit('master-advances-while-stopped');
        $this->command([
            'systemctl',
            '--user',
            'start',
            'baander-development-' . $this->store->scope() . '.service',
        ]);
        Support::check($this->state()['phase'] === 'stopped', 'Queued timer tick ignored stopped intent.');
        $deadline = Support::now() + 125;
        do {
            Support::check(
                $this->state()['phase'] === 'stopped' && !$this->store->enabled('persistent'),
                'Timer re-enabled a stopped instance.',
            );
            $this->commands->pause(1000000);
        } while (Support::now() < $deadline);
        $this->up();
        $this->ready($target);
        Support::check($this->fingerprint() === $original, 'Stop/resume changed retained identity.');
        Support::check($this->mediaHashes() === $originalMedia, 'Stop/resume changed source media.');
        $this->record(
            'down during preparation cancels activation; timer stays stopped; up retains state',
            ['revision' => $target],
        );
        $this->mutationFailure($original);
        Support::check($this->mediaHashes() === $originalMedia, 'Mutation failure changed source media.');
        $this->record('original source media hashes preserved', ['files' => $originalMedia]);
        $this->cleanup();
        $this->record('qualification complete');
    }

    /** @param array<string,mixed> $original */
    private function mutationFailure(array $original): void
    {
        $instance = $this->state();
        $docker = $this->docker();
        $container = trim($docker->compose($instance['project'], $instance['compose_file'], ['ps', '-q', 'postgres'])->stdout);
        $network = json_decode(
            $docker->run(['inspect', $container])->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        )[0]['NetworkSettings']['Networks'];
        $address = $network[Runtime::networkName($instance)]['IPAddress'];
        Support::check(
            filter_var($address, FILTER_VALIDATE_IP) !== false,
            'Owned database has an invalid network address.',
        );
        $migration = <<<'MIGRATION'
<?php
 declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20990101000000 extends AbstractMigration
{
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_qualification_committed (id integer PRIMARY KEY)');
        $this->addSql('INSERT INTO workflow_qualification_committed VALUES (1)');
        $this->addSql("SELECT pg_sleep(CASE WHEN host(inet_server_addr()) = 'ADDRESS' THEN 35 ELSE 0 END)");
        $this->addSql("DO \$\$ BEGIN IF host(inet_server_addr()) = 'ADDRESS' THEN RAISE EXCEPTION 'Owned qualification failure after commit'; END IF; END \$\$");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException(); }
}
MIGRATION;
        file_put_contents(
            $this->checkout . '/migrations/Version20990101000000.php',
            str_replace('ADDRESS', $address, $migration),
        );
        $this->command(['git', 'add', 'migrations']);
        $target = $this->commit('partial-mutation-failure');
        $this->poll(
            fn(): bool => ($this->state()['target_revision'] ?? null) === $target
                && ($this->state()['phase'] ?? null) === 'database_mutating',
            'live migration',
        );
        $this->poll(
            fn(): bool => $this->sql("SELECT json_build_object('committed',to_regclass('workflow_qualification_committed') IS NOT NULL);")['committed'],
            'committed live mutation',
        );
        $down = $this->commands->start(
            $this->cliArguments('down', ['--instance', 'persistent']),
            timeout: 300,
        );
        $this->poll(fn(): bool => !$this->store->enabled('persistent'), 'down intent during migration');
        $down->pump();
        Support::check($down->result() === null, 'Down killed or bypassed the active migration');
        $result = $this->commands->wait($down);
        Support::check(
            $result->exitCode === 0 && !$result->timedOut && !$result->cancelled,
            'Down failed after migration finished',
        );
        $current = $this->state();
        Support::check(
            $current['phase'] === 'blocked' && empty($current['running_revision']) && empty($current['readiness']),
            'Partial migration did not block readiness.',
        );
        Support::check(
            ($current['checkpoint']['verified'] ?? null) === true,
            'Partial mutation has no verified checkpoint.',
        );
        new Client($this->executor, Target::fromArray($current['docker']), $this->environment)->compose($current['project'], $current['compose_file'], ['up', '-d', '--wait', 'postgres']);
        Support::check(
            $this->sql("SELECT json_build_object('rows',count(*)) FROM workflow_qualification_committed;")['rows'] === 1,
            'Committed migration evidence was lost.',
        );
        Support::check($this->fingerprint() === $original, 'Partial mutation changed retained identities.');
        $this->cli('recover', ['--instance', 'persistent'], expected: 1);
        Support::check($this->state()['phase'] === 'blocked', 'Unsafe recovery left blocked phase.');
        $this->record(
            'down waits for live migration; partial commit blocks readiness and unsafe recovery',
            ['failed_revision' => $target, 'checkpoint' => $current['checkpoint']['directory']],
        );
    }
}
