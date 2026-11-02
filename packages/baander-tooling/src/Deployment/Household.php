<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Configuration\{ApplicationLayout, JsonObject, ToolingConfiguration};
use Baander\Tooling\Docker\{Client, Target, TargetResolver};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\{CommandExecutor, EngineExecutor, ExecutionContext};
use Baander\Tooling\Resources\DisposableContainer;

final readonly class Household
{
    /** @param array<string,string> $environment */
    public function __construct(
        private HouseholdStateStore $store,
        private Client $docker,
        private CommandExecutor $executor,
        private array $environment,
        private string $source,
        private float $cleanupSeconds = 120.0,
    ) {}

    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $source, array $environment): int
    {
        $action = array_shift($arguments);
        $options = [];
        while ($arguments !== []) {
            $key = array_shift($arguments);
            $value = array_shift($arguments);
            if (!in_array($key, ['--config', '--state'], true) || !is_string($value) || $value === '' || isset($options[$key])) {
                throw new \InvalidArgumentException('Use deploy-household.php render|start|stop|status --state ABSOLUTE_DIRECTORY [--config FILE].');
            }
            $options[$key] = $value;
        }
        if (!in_array($action, ['render', 'start', 'stop', 'status'], true) || !isset($options['--state'])
            || (in_array($action, ['render', 'start'], true) && !isset($options['--config']))) {
            throw new \InvalidArgumentException('Use deploy-household.php render|start|stop|status --state ABSOLUTE_DIRECTORY [--config FILE].');
        }
        $store = new HouseholdStateStore($options['--state']);
        $lock = $store->lock();
        $configuration = ToolingConfiguration::forRepository($source, $environment);
        $executor = new EngineExecutor(new ExecutionContext($environment));
        try {
            $saved = $store->load();
            $target = $saved === null
                ? new TargetResolver($executor, $environment)->resolve($configuration->docker, $store->directory . '/transport')
                : Target::fromArray($saved['target']);
            $household = new self($store, new Client($executor, $target, $environment), $executor, $environment, $source, $configuration->budgets->seconds('cleanup'));
            $result = match ($action) {
                'render' => $household->render(HouseholdConfiguration::fromArray(JsonObject::read($options['--config']))),
                'start' => $household->start(HouseholdConfiguration::fromArray(JsonObject::read($options['--config']))),
                'stop' => $household->stop(),
                'status' => $household->status(),
            };
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            return 0;
        } finally {
            $executor->close();
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public function render(HouseholdConfiguration $configuration): array
    {
        $previous = $this->store->load();
        if ($previous !== null && !in_array($previous['phase'] ?? null, ['prepared', 'stopped'], true)) {
            throw new \RuntimeException('Stop the saved deployment before rendering its replacement.');
        }
        if ($previous !== null && (($previous['configuration']['name'] ?? null) !== $configuration->name
            || ($previous['configuration']['volumes'] ?? null) != $configuration->volumes)) {
            throw new \RuntimeException('Use a separate state directory for a different deployment or persistent volume inventory.');
        }
        $this->docker->validateIdentity();
        $this->docker->requireCompose();
        $images = [];
        $layout = null;
        $profile = null;
        $revision = null;
        foreach ($configuration->images as $role => $selection) {
            $resolved = $this->docker->resolveImage($selection, false);
            $images[$role] = $resolved->toArray();
            if (!in_array($role, ['application', 'worker', 'operator'], true)) {
                continue;
            }
            $inspection = json_decode($this->docker->run(['image', 'inspect', $resolved->id])->stdout, true, flags: JSON_THROW_ON_ERROR);
            $candidateLayout = ApplicationLayout::fromImage($inspection[0]);
            $candidateRevision = $inspection[0]['Config']['Labels']['org.opencontainers.image.revision'] ?? null;
            if (!is_string($candidateRevision) || $candidateRevision === '' || ($revision !== null && $revision !== $candidateRevision)) {
                throw new \RuntimeException('Application, worker and operator images must declare the same source revision.');
            }
            $revision = $candidateRevision;
            $candidateProfile = ImageProfile::resolve($this->docker, $resolved->id, $candidateLayout, $configuration->profile, $this->store->directory . '/profile-' . $role . '.json', $this->environment, $this->cleanupSeconds, $configuration->resources['operatorMemoryMiB']);
            if (($layout !== null && $layout->toArray() !== $candidateLayout->toArray())
                || ($profile !== null && $profile->fingerprint() !== $candidateProfile->fingerprint())) {
                throw new \RuntimeException('Application, worker and operator images disagree on layout or deployment profile.');
            }
            $layout = $candidateLayout;
            $profile = $candidateProfile;
        }
        if ($layout === null || $profile === null) {
            throw new \RuntimeException('Missing application release identity.');
        }
        $postgresPath = $configuration->profile === 'household' ? '/deploy/household/postgresql.conf' : '/docker/postgres/conf/postgresql.conf';
        $postgres = file_get_contents($this->source . $postgresPath);
        if ($postgres === false) {
            throw new \RuntimeException('Cannot read the selected PostgreSQL configuration.');
        }
        new PostgresSettings($postgres);
        $owner = $previous['owner'] ?? bin2hex(random_bytes(16));
        $compose = HouseholdRenderer::render($configuration, $images, $layout, $profile, $postgres, $owner);
        $content = json_encode($compose, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $state = ['version' => 1, 'phase' => 'prepared', 'owner' => $owner, 'configuration' => $configuration->toArray(),
            'target' => $this->docker->target->toArray(), 'images' => $images, 'layout' => $layout->toArray(),
            'profile' => $profile->toArray(), 'profileFingerprint' => $profile->fingerprint(), 'sourceRevision' => $revision,
            'configurationVolumes' => array_values(array_unique([ ...($previous['configurationVolumes'] ?? []), ...array_column(HouseholdRenderer::configurationFiles($configuration, $postgres), 'volume')])),
            'composeHash' => hash('sha256', $content), 'composeFile' => $this->store->directory . '/compose-' . hash('sha256', $content) . '.json', 'postgresConfiguration' => $postgres];
        AtomicFile::write($state['composeFile'], $content);
        $this->store->save($state);
        $this->docker->compose($configuration->name, $this->composeFile(), ['config', '--quiet']);
        return $this->report($state);
    }

    /** @return array<string,mixed> */
    public function start(HouseholdConfiguration $configuration): array
    {
        $previous = $this->store->load();
        if ($previous !== null) {
            if (($previous['configuration']['name'] ?? null) !== $configuration->name || ($previous['configuration']['volumes'] ?? null) != $configuration->volumes) {
                throw new \RuntimeException('Use a separate state directory for a different deployment or persistent volume inventory.');
            }
            $this->stop();
        }
        $this->render($configuration);
        $state = $this->requiredState();
        $profile = ImageProfile::fromArray($state['profile']);
        $layout = ApplicationLayout::fromArray($state['layout']);
        $information = $this->docker->validateIdentity();
        $required = HouseholdRenderer::reservations($configuration, $profile)['totalMiB'];
        $lastCpu = max([...$configuration->controlCpuSet, ...$configuration->executionCpuSet]);
        if (($information['MemTotal'] ?? 0) < $required * 1024 ** 2 || ($information['NCPU'] ?? 0) <= $lastCpu) {
            throw new \RuntimeException('The Docker host does not cover the configured complete deployment reservation (' . $required . ' MiB) and CPU sets. This is a configured envelope, not a measured minimum.');
        }
        $state['phase'] = 'starting';
        $this->store->save($state);
        $this->assertOwnedServices($state);
        $this->prepareVolumes($configuration, $state);
        $this->docker->compose($configuration->name, $this->composeFile(), ['up', '-d', '--wait', 'postgres', 'redis'], timeout: 180);
        $this->migrate($configuration, $state, $layout, $profile);
        $this->initializeSecrets($configuration, $state, $layout, $profile);
        $this->verifyPostgres($configuration, $state);
        $this->docker->compose($configuration->name, $this->composeFile(), ['up', '-d', '--wait', 'web', 'gateway'], timeout: 180);
        $workers = $this->store->workers();
        $record = $workers->load();
        $lifecycle = new WorkerLifecycle($workers, $this->executor, $this->environment);
        $lifecycle->prepare($record, new WorkerConfiguration(
            namespace: $configuration->name,
            target: $this->docker->target->toArray(),
            network: $configuration->name . '-private',
            workerImage: $state['images']['worker']['id'],
            operatorImage: $state['images']['operator']['id'],
            layout: $layout,
            runtimeEnvironment: HouseholdRenderer::workerEnvironment($configuration, $profile),
            sourceMountPolicy: 'production',
            mediaMounts: HouseholdRenderer::mediaMounts($configuration, $layout),
            resourceLabels: ['org.baander.household' => $configuration->name, 'org.baander.household.owner' => $state['owner']],
            profile: $profile,
            cleanupSeconds: $this->cleanupSeconds,
            controlCpuSet: $configuration->controlCpuSet,
            executionCpuSet: $configuration->executionCpuSet,
            operatorMemoryMiB: $configuration->resources['operatorMemoryMiB'],
        ));
        $lifecycle->start($record);
        $lifecycle->ready($record);
        $state['phase'] = 'running';
        $this->store->save($state);
        return $this->report($state);
    }

    /** @return array<string,mixed> */
    public function stop(): array
    {
        $state = $this->requiredState();
        $this->validateSaved($state);
        $configuration = HouseholdConfiguration::fromArray($state['configuration']);
        $this->assertOwnedServices($state);
        $state['phase'] = 'stopping';
        $this->store->save($state);
        $workers = $this->store->workers();
        $record = $workers->load();
        if ($record !== null && ($record['phase'] ?? null) !== 'retired') {
            new WorkerLifecycle($workers, $this->executor, $this->environment)->retire($record);
        }
        $this->docker->compose($configuration->name, $this->composeFile(), ['down', '--timeout', '40'], timeout: 180);
        $state['phase'] = 'stopped';
        $this->store->save($state);
        return $this->report($state);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state = $this->requiredState();
        $this->validateSaved($state);
        $result = $this->report($state);
        $workers = $this->store->workers();
        $result['workersRunning'] = new WorkerLifecycle($workers, $this->executor, $this->environment)->running($workers->load());
        $configuration = HouseholdConfiguration::fromArray($state['configuration']);
        $output = trim($this->docker->compose($configuration->name, $this->composeFile(), ['ps', '--all', '--format', 'json'])->stdout);
        $services = json_decode($output, true);
        if (!is_array($services)) {
            $services = $output === '' ? [] : array_map(static fn(string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", $output));
        } elseif (!array_is_list($services)) {
            $services = [$services];
        }
        $result['services'] = $services;
        return $result;
    }

    /** @param array<string,mixed> $state */
    private function validateSaved(array $state): void
    {
        if (($state['target'] ?? null) != $this->docker->target->toArray()
            || !hash_equals($state['composeHash'], hash_file('sha256', $this->composeFile()) ?: '')) {
            throw new \RuntimeException('Saved deployment target or configuration changed; preserve state for recovery.');
        }
        $this->docker->validateIdentity();
        $profile = ImageProfile::fromArray($state['profile']);
        if (($state['configuration']['profile'] ?? null) !== $profile->deployment['identifier'] || !hash_equals($state['profileFingerprint'], $profile->fingerprint())) {
            throw new \RuntimeException('Saved deployment profile changed; preserve state for recovery.');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertOwnedServices(array $state): void
    {
        $name = $state['configuration']['name'];
        $ids = preg_split('/\s+/', trim($this->docker->compose($name, $this->composeFile(), ['ps', '--all', '--quiet'])->stdout), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        if ($ids !== []) {
            $containers = json_decode($this->docker->run(['inspect', ...$ids])->stdout, true, flags: JSON_THROW_ON_ERROR);
            foreach ($containers as $container) {
                if (($container['Config']['Labels']['org.baander.household.owner'] ?? null) !== $state['owner']) {
                    throw new \RuntimeException('Compose project contains a container owned by another deployment.');
                }
            }
        }
        $network = $this->docker->run(['network', 'inspect', $name . '-private'], check: false);
        if ($network->exitCode === 0) {
            $document = json_decode($network->stdout, true, flags: JSON_THROW_ON_ERROR);
            if (($document[0]['Labels']['org.baander.household.owner'] ?? null) !== $state['owner']) {
                throw new \RuntimeException('Private network is owned by another deployment.');
            }
        }
    }

    /** @param array<string,mixed> $state */
    private function prepareVolumes(HouseholdConfiguration $configuration, array $state): void
    {
        $files = HouseholdRenderer::configurationFiles($configuration, $state['postgresConfiguration']);
        $volumes = $configuration->volumes + array_map(static fn(array $file): string => $file['volume'], $files);
        foreach ($volumes as $role => $name) {
            $inspection = $this->docker->run(['volume', 'inspect', $name], check: false);
            if ($inspection->exitCode === 0) {
                $document = json_decode($inspection->stdout, true, flags: JSON_THROW_ON_ERROR);
                if (($document[0]['Labels']['org.baander.household.owner'] ?? null) !== $state['owner']) {
                    throw new \RuntimeException('Persistent volume is not owned by this saved deployment: ' . $name);
                }
            } else {
                $this->docker->run(['volume', 'create', '--label', 'org.baander.household.owner=' . $state['owner'], '--label', 'org.baander.household=' . $configuration->name, $name]);
            }
            if (isset($files[$role])) {
                DisposableContainer::run($this->docker, $this->store->directory . '/seed-' . $role . '.json', [
                    '--memory', $configuration->resources['operatorMemoryMiB'] . 'm', '--memory-swap', $configuration->resources['operatorMemoryMiB'] . 'm',
                    '--network', 'none', '--user', '0:0', '--entrypoint', 'php', '--mount', 'type=volume,src=' . $name . ',dst=/seed',
                    $state['images']['application']['id'], '-r', '$path = "/seed/" . $argv[1]; if (file_put_contents($path, stream_get_contents(STDIN)) === false || !chmod($path, 0644)) { exit(1); }', $files[$role]['name'],
                ], $this->environment, $this->cleanupSeconds, input: $files[$role]['content']);
                continue;
            }
            if (in_array($role, ['postgres', 'redis'], true)) {
                continue;
            }
            DisposableContainer::run($this->docker, $this->store->directory . '/seed-' . $role . '.json', [
                '--memory', $configuration->resources['operatorMemoryMiB'] . 'm', '--memory-swap', $configuration->resources['operatorMemoryMiB'] . 'm',
                '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh', '--mount', 'type=volume,src=' . $name . ',dst=/seed',
                $state['images']['application']['id'], '-ec', 'chown www-data:www-data /seed',
            ], $this->environment, $this->cleanupSeconds);
        }
    }

    /** @param array<string,mixed> $state */
    private function migrate(HouseholdConfiguration $configuration, array $state, ApplicationLayout $layout, ImageProfile $profile): void
    {
        $arguments = ['--network', $configuration->name . '-private', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--memory', $configuration->resources['operatorMemoryMiB'] . 'm', '--memory-swap', $configuration->resources['operatorMemoryMiB'] . 'm', '--entrypoint', 'php'];
        $arguments[] = '--mount';
        $arguments[] = 'type=volume,src=' . $configuration->volumes['secrets'] . ',dst=' . $layout->path('config/secrets');
        $environment = HouseholdRenderer::environment($configuration, $profile);
        foreach ($environment as $key => $_) {
            array_push($arguments, '--env', $key);
        }
        array_push($arguments, $state['images']['application']['id'], '-d', 'memory_limit=' . $profile->phpHeapMiB . 'M', $layout->path('bin/console'), 'doctrine:migrations:migrate', '--no-interaction');
        DisposableContainer::run($this->docker, $this->store->directory . '/migration.json', $arguments, array_replace($this->environment, $environment), $this->cleanupSeconds, timeout: 600, containerEnvironment: $environment);
    }

    /** @param array<string,mixed> $state */
    private function initializeSecrets(HouseholdConfiguration $configuration, array $state, ApplicationLayout $layout, ImageProfile $profile): void
    {
        $arguments = ['--network', $configuration->name . '-private', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--memory', $configuration->resources['operatorMemoryMiB'] . 'm', '--memory-swap', $configuration->resources['operatorMemoryMiB'] . 'm',
            '--mount', 'type=volume,src=' . $configuration->volumes['secrets'] . ',dst=' . $layout->path('config/secrets'), '--entrypoint', '/bin/sh'];
        $environment = HouseholdRenderer::environment($configuration, $profile);
        foreach ($environment as $key => $_) {
            array_push($arguments, '--env', $key);
        }
        $program = <<<'SH'
private="$1/config/secrets/oauth/private.key"
public="$1/config/secrets/oauth/public.key"
if [ -e "$private" ] || [ -L "$private" ] || [ -e "$public" ] || [ -L "$public" ]; then
    test -f "$private" && test ! -L "$private" && test -f "$public" && test ! -L "$public" || { echo 'Incomplete OAuth key pair; preserve the secret volume for recovery.' >&2; exit 1; }
else
    exec php -d "memory_limit=$2" "$1/bin/console" app:oauth:generate-keys --no-interaction
fi
SH;
        array_push($arguments, $state['images']['application']['id'], '-ec', $program, 'household-secrets', $layout->root, $profile->phpHeapMiB . 'M');
        DisposableContainer::run($this->docker, $this->store->directory . '/secrets-bootstrap.json', $arguments, $this->environment, $this->cleanupSeconds, timeout: 120, containerEnvironment: $environment);
    }

    /** @param array<string,mixed> $state */
    private function verifyPostgres(HouseholdConfiguration $configuration, array $state): void
    {
        $settings = new PostgresSettings($state['postgresConfiguration']);
        $output = $this->docker->compose($configuration->name, $this->composeFile(), ['exec', '-T', 'postgres', 'psql', '-X', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-U', $configuration->environment['DB_USERNAME'], '-d', $configuration->environment['DB_DATABASE']], input: $settings->verificationSql())->stdout;
        $snapshot = null;
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with(trim($line), '{')) {
                $snapshot = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        if (!is_array($snapshot)) {
            throw new \RuntimeException('PostgreSQL returned no effective configuration snapshot.');
        }
        AtomicFile::writeJson($this->store->directory . '/postgres-effective.json', $snapshot);
        $errors = $settings->verify($snapshot, HouseholdRenderer::POSTGRES_CONFIG_PATH);
        if ($errors !== []) {
            throw new \RuntimeException('PostgreSQL effective settings differ: ' . implode('; ', $errors));
        }
    }

    /** @return array<string,mixed> */
    private function requiredState(): array
    {
        return $this->store->load() ?? throw new \RuntimeException('Render or start a deployment in this state directory first.');
    }

    private function composeFile(): string
    {
        $path = $this->requiredState()['composeFile'];
        if (!is_string($path) || !str_starts_with($path, $this->store->directory . '/compose-') || str_contains(substr($path, strlen($this->store->directory) + 1), '/')) {
            throw new \RuntimeException('Invalid saved Compose path.');
        }
        return $path;
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function report(array $state): array
    {
        $configuration = HouseholdConfiguration::fromArray($state['configuration']);
        return ['name' => $configuration->name, 'phase' => $state['phase'], 'profile' => $configuration->profile,
            'profileFingerprint' => $state['profileFingerprint'], 'origin' => $configuration->origin,
            'composeFile' => $this->composeFile(), 'redisMaxmemoryMiB' => $configuration->resources['redisMaxmemoryMiB'], 'dataVolumes' => $configuration->volumes, 'configurationVolumes' => $state['configurationVolumes'], 'reservations' => HouseholdRenderer::reservations($configuration, ImageProfile::fromArray($state['profile'])),
            'qualification' => 'Unqualified candidate limits; configured reservations are not measured consumption or a minimum host requirement.'];
    }
}
