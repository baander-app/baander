<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Configuration\{ApplicationLayout, JsonObject, ToolingConfiguration};
use Baander\Tooling\Deployment\{ContainerMeasurement, Household, HouseholdConfiguration, HouseholdStateStore, ImageProfile, WorkerLifecycle};
use Baander\Tooling\Docker\{Client, Target, TargetResolver};
use Baander\Tooling\Filesystem\{AtomicFile, FileLock, PrivateDirectory};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\RunningProcess;
use Baander\Tooling\Resources\{DisposableContainer, ImageLedger, ResourceKind, ResourceLedger};

final class Qualification
{
    private const float COLD_MINIMUM_SECONDS = 5.0;
    /** @var array<string,int> */
    private array $observedRoles = [];
    /** @var list<string> */
    private array $unexpectedExits = [];
    /** @var list<array{epochSeconds:float,output:string}> */
    private array $webControlRetries = [];
    /** @param array<string,mixed> $configuration */
    public function __construct(private readonly Runtime $runtime, private readonly Client $docker, private readonly string $directory, private readonly array $configuration)
    {
        PrivateDirectory::prepare($directory);
    }

    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $source, array $environment): int
    {
        $action = array_shift($arguments);
        $options = [];
        while ($arguments !== []) {
            $key = array_shift($arguments);
            if ($key === '--cleanup') {
                $options[$key] = true;
                continue;
            }
            $value = array_shift($arguments);
            if (!in_array($key, ['--config', '--state', '--scenario', '--repeat', '--from'], true) || !is_string($value) || $value === '' || isset($options[$key])) {
                throw new \InvalidArgumentException('Use qualify-household.php prepare|start|scenario|stop|report --state DIRECTORY [--config FILE] [--from SEED_STATE] [--scenario NAME --repeat NUMBER] [--cleanup].');
            }
            $options[$key] = $value;
        }
        if (!in_array($action, ['prepare', 'start', 'scenario', 'stop', 'report'], true) || !is_string($options['--state'] ?? null) || !str_starts_with($options['--state'], '/')) {
            throw new \InvalidArgumentException('Qualification requires an action and absolute --state directory.');
        }
        if ((isset($options['--cleanup']) && $action !== 'stop') || (isset($options['--repeat']) && ($action !== 'scenario' || preg_match('/^[123]$/D', $options['--repeat']) !== 1))) {
            throw new \InvalidArgumentException('Use --cleanup only for stop and --repeat 1, 2 or 3 only for a scenario.');
        }
        $directory = $options['--state'];
        PrivateDirectory::prepare($directory);
        $directory = realpath($directory) ?: throw new \RuntimeException('Cannot resolve qualification state directory.');
        $from = isset($options['--from']) ? self::sourceDirectory($options['--from'], $directory) : null;
        if ($from !== null && $action !== 'prepare') {
            throw new \InvalidArgumentException('--from applies only to prepare.');
        }
        $lockDirectories = $from === null ? [$directory] : [$directory, $from];
        sort($lockDirectories, SORT_STRING);
        $locks = [];
        foreach ($lockDirectories as $lockedDirectory) {
            $locks[] = FileLock::acquire($lockedDirectory . '/operation.lock');
        }
        $saved = is_file($directory . '/qualification.json') ? AtomicFile::readJson($directory . '/qualification.json') : null;
        $configuration = self::validateConfiguration(isset($options['--config']) ? JsonObject::read($options['--config']) : ($saved['configuration'] ?? []));
        if ($saved !== null && ($saved['configuration'] ?? null) != $configuration) {
            throw new \RuntimeException('Saved qualification configuration differs; use a fresh state directory.');
        }
        $tooling = ToolingConfiguration::forRepository($source, $environment, ['budgets' => ['fixture' => $configuration['fixtureTimeoutSeconds']]]);
        $runtime = new Runtime($source, max($configuration['fixtureTimeoutSeconds'], $configuration['maintenanceDeadlineSeconds'] + 1800), $environment, $tooling);
        try {
            $target = $saved === null ? new TargetResolver($runtime->executor, $environment)->resolve($tooling->docker, $directory . '/transport') : Target::fromArray($saved['target']);
            $qualification = new self($runtime, new Client($runtime->executor, $target, $environment), $directory, $configuration);
            $result = match ($action) {
                'prepare' => $qualification->prepare($from),
                'start' => $qualification->start(),
                'scenario' => $qualification->scenario((string) ($options['--scenario'] ?? ''), (int) ($options['--repeat'] ?? 1)),
                'stop' => $qualification->stop(($options['--cleanup'] ?? false) === true),
                'report' => $qualification->report(),
            };
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            return isset($result['failure']) ? 1 : 0;
        } finally {
            $runtime->close();
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    public static function validateConfiguration(array $value): array
    {
        JsonObject::keys($value, ['version', 'name', 'deployment', 'datasetRun', 'fixtureTimeoutSeconds', 'durationSeconds', 'maintenanceDeadlineSeconds', 'observerMemoryMiB', 'nodeExecutable', 'chromiumExecutablePath', 'host'], 'household qualification');
        if (($value['version'] ?? null) !== 1 || !is_array($value['deployment'] ?? null) || !is_array($value['host'] ?? null)) {
            throw new \InvalidArgumentException('Qualification requires version, deployment and host evidence.');
        }
        $deployment = HouseholdConfiguration::fromArray($value['deployment']);
        if (($value['name'] ?? null) !== $deployment->name) {
            throw new \InvalidArgumentException('Qualification and deployment names must agree.');
        }
        if (!is_string($value['datasetRun'] ?? null)) {
            throw new \InvalidArgumentException('Record the deterministic dataset run identifier.');
        }
        Dataset::plan($value['datasetRun']);
        $value += ['fixtureTimeoutSeconds' => 7200, 'durationSeconds' => 1800, 'maintenanceDeadlineSeconds' => 7200, 'observerMemoryMiB' => 128, 'nodeExecutable' => '/usr/bin/node'];
        foreach (['fixtureTimeoutSeconds', 'durationSeconds', 'maintenanceDeadlineSeconds', 'observerMemoryMiB'] as $key) {
            if (!is_int($value[$key]) || $value[$key] < 1) {
                throw new \InvalidArgumentException('Qualification budgets require positive integers.');
            }
        }
        if ($value['durationSeconds'] < 1800 || $value['maintenanceDeadlineSeconds'] < max(3600, $value['durationSeconds']) || $value['fixtureTimeoutSeconds'] < 3600 || $value['observerMemoryMiB'] < 64) {
            throw new \InvalidArgumentException('Keep overlap >=1800 seconds and fixture/maintenance budgets >=3600 seconds.');
        }
        foreach (['nodeExecutable', 'chromiumExecutablePath'] as $key) {
            if (isset($value[$key]) && (!is_string($value[$key]) || !str_starts_with($value[$key], '/'))) {
                throw new \InvalidArgumentException('Browser tools require absolute executable paths.');
            }
        }
        if (!is_int($value['host']['constraintBytes'] ?? null) || $value['host']['constraintBytes'] < 1
            || !is_string($value['host']['storage'] ?? null) || $value['host']['storage'] === ''
            || !is_string($value['host']['description'] ?? null) || $value['host']['description'] === '') {
            throw new \InvalidArgumentException('Record whole-host memory constraint, storage and hardware description.');
        }
        $value['deployment'] = $deployment->toArray();
        return $value;
    }

    /** @return array<string,mixed> */
    public function prepare(?string $from = null): array
    {
        if (is_file($this->statePath())) {
            throw new \RuntimeException('Qualification state already exists; resume its saved stage or use a fresh directory.');
        }
        if ($from !== null) {
            return $this->prepareFrom($from);
        }
        $state = ['version' => 1, 'phase' => 'preparing', 'configuration' => $this->configuration,
            'target' => $this->docker->target->toArray(), 'runs' => [], 'freshCopyConsumed' => false];
        $this->save($state);
        $deployment = HouseholdConfiguration::fromArray($this->configuration['deployment']);
        $household = $this->household();
        $household->render($deployment);
        $deploymentState = $this->deploymentState();
        $volume = $deployment->volumes['media'];
        if ($this->docker->run(['volume', 'inspect', $volume], check: false)->exitCode === 0) {
            throw new \RuntimeException('Source volume already exists; use a fresh qualification deployment name.');
        }
        new ResourceLedger($this->directory . '/source-volume.json', $this->docker, $this->runtime->environment)->create(
            ResourceKind::Volume,
            $volume,
            ['--label', 'org.baander.household.owner=' . $deploymentState['owner'], '--label', 'org.baander.household=' . $deployment->name],
        );
        PrivateDirectory::prepare($this->directory . '/dataset');
        $manifest = Dataset::prepare(
            $this->docker,
            $this->runtime,
            $deploymentState['images']['application']['id'],
            $volume,
            $this->directory . '/dataset',
            $this->configuration['datasetRun'],
            max(1860, $this->configuration['durationSeconds'] + 60),
        );
        AtomicFile::writeJson($this->directory . '/dataset-manifest.json', $manifest);
        $layout = ApplicationLayout::fromArray($deploymentState['layout']);
        $images = new Images($this->docker, $this->directory . '/images', $layout)->build($deploymentState['images']['application']['id'], $deploymentState['images']['operator']['id'], $deploymentState['sourceRevision'], $this->directory . '/dataset-manifest.json');
        $effective = $deployment->toArray();
        foreach (['application' => 'app', 'worker' => 'worker', 'operator' => 'operator'] as $role => $image) {
            $effective['images'][$role] = $images[$image];
        }
        $effective['resources']['hostHeadroomMiB'] += $this->configuration['observerMemoryMiB'];
        $household->render(HouseholdConfiguration::fromArray($effective));
        AtomicFile::writeJson($this->directory . '/deployment-configuration.json', $effective);
        $state['effectiveDeployment'] = $effective;
        $state['images'] = $images;
        $state['hostObservation'] = $this->docker->validateIdentity();
        if (($state['hostObservation']['MemTotal'] ?? PHP_INT_MAX) > $this->configuration['host']['constraintBytes']) {
            throw new \RuntimeException('Docker host exceeds the declared whole-host memory constraint.');
        }
        $state['datasetDigest'] = self::datasetDigest($manifest);
        $state['phase'] = 'prepared';
        $state['preparation'] = ['outsideMeasurement' => true, 'filesystemCache' => 'Generation may leave a warm host page cache; cold means new catalog and first import, not cold filesystem cache.'];
        $this->save($state);
        return $this->publicState($state);
    }

    public static function sourceDirectory(string $from, string $destination): string
    {
        if (!str_starts_with($from, '/') || !str_starts_with($destination, '/')) {
            throw new \InvalidArgumentException('Fixture reuse requires absolute state directories.');
        }
        PrivateDirectory::assertNoSymlinks($from);
        PrivateDirectory::assertNoSymlinks($destination);
        $source = realpath($from);
        $target = realpath($destination);
        if ($source === false || $target === false || $source === $target || str_starts_with($source . '/', $target . '/') || str_starts_with($target . '/', $source . '/')) {
            throw new \InvalidArgumentException('Source and child state directories must exist and must not contain one another.');
        }
        return $source;
    }

    /** @param array<string,mixed> $seed
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $target
     */
    public static function validateSeed(array $seed, array $configuration, array $target): void
    {
        if (($seed['version'] ?? null) !== 1 || !in_array($seed['phase'] ?? null, ['prepared', 'running', 'stopped'], true)
            || isset($seed['borrowedFrom']) || isset($seed['pendingRename'])
            || isset($seed['changedSourcePath']) || isset($seed['activeRun'])
            || ($seed['target']['daemonId'] ?? null) !== ($target['daemonId'] ?? null) || ($seed['target']['endpoint'] ?? null) !== ($target['endpoint'] ?? null)) {
            throw new \RuntimeException('Fixture seed must be an idle original prepared/imported state on the same Docker target, without source renames.');
        }
        if (($seed['configuration']['name'] ?? null) === $configuration['name']) {
            throw new \RuntimeException('A child requires a distinct deployment name.');
        }
        foreach (['datasetRun', 'durationSeconds', 'maintenanceDeadlineSeconds', 'observerMemoryMiB'] as $key) {
            if (($seed['configuration'][$key] ?? null) !== $configuration[$key]) {
                throw new \RuntimeException('Fixture reuse must preserve workload timing and dataset: ' . $key);
            }
        }
        foreach (['images'] as $key) {
            if (($seed['configuration']['deployment'][$key] ?? null) != $configuration['deployment'][$key]) {
                throw new \RuntimeException('Fixture reuse must preserve non-profile deployment ' . $key . '.');
            }
        }
        foreach (array_keys($seed['runs'] ?? []) as $run) {
            if ($run !== 'cold-startup') {
                throw new \RuntimeException('A fixture seed cannot have attempted overlap scenarios.');
            }
        }
    }

    /** @param array<string,mixed> $state */
    public static function assertBorrowersCleaned(array $state, string $directory): void
    {
        foreach ($state['borrowers'] ?? [] as $path => $record) {
            $child = AtomicFile::readJson($path . '/qualification.json');
            if (($child['phase'] ?? null) !== 'cleaned' || ($child['borrowedFrom']['stateDirectory'] ?? null) !== $directory
                || ($child['borrowedFrom']['borrowerId'] ?? null) !== $record['borrowerId']) {
                throw new \RuntimeException('Retain shared fixture images until registered child cleanup completes: ' . $path);
            }
        }
    }

    /** @return array<string,mixed> */
    private function prepareFrom(string $from): array
    {
        $seed = AtomicFile::readJson($from . '/qualification.json');
        self::validateSeed($seed, $this->configuration, $this->docker->target->toArray());
        $seedDeployment = new HouseholdStateStore($from . '/deployment')->load() ?? throw new \RuntimeException('Fixture seed deployment is missing.');
        $manifestText = file_get_contents($from . '/dataset-manifest.json');
        if ($manifestText === false || strlen($manifestText) > 16777216
            || hash('sha256', $manifestText) !== ($seed['images']['manifest']['files']['files/var/household/dataset.json'] ?? null)) {
            throw new \RuntimeException('Seed dataset manifest differs from the immutable image input.');
        }
        $manifest = json_decode($manifestText, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || self::datasetDigest($manifest) !== $seed['datasetDigest']) {
            throw new \RuntimeException('Seed dataset identity is invalid.');
        }
        $imageOwnership = AtomicFile::readJson($seed['images']['ownershipReceipt']);
        foreach (['app', 'worker', 'operator'] as $role) {
            $inspection = json_decode($this->docker->run(['image', 'inspect', $seed['images'][$role]])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
            if (($inspection['Id'] ?? null) !== $seed['images'][$role]
                || ($inspection['Config']['Labels'][Images::OVERLAY_LABEL] ?? null) !== $seed['images']['manifestDigest']
                || ($inspection['Config']['Labels']['org.baander.tooling.run'] ?? null) !== $imageOwnership['token']) {
                throw new \RuntimeException('Seed overlay image identity or ownership changed.');
            }
        }
        $effective = $this->configuration['deployment'];
        foreach (['application' => 'app', 'worker' => 'worker', 'operator' => 'operator'] as $role => $image) {
            $effective['images'][$role] = $seed['images'][$image];
        }
        foreach (['postgres', 'gateway', 'redis'] as $role) {
            $effective['images'][$role] = $seedDeployment['images'][$role]['id'];
        }
        $effective['resources']['hostHeadroomMiB'] += $this->configuration['observerMemoryMiB'];
        if (array_intersect(array_values($effective['volumes']), array_values($seedDeployment['configuration']['volumes'])) !== []) {
            throw new \RuntimeException('Every child data volume must be separate from the seed.');
        }
        $borrowerId = bin2hex(random_bytes(16));
        $state = ['version' => 1, 'phase' => 'preparing', 'configuration' => $this->configuration, 'target' => $this->docker->target->toArray(),
            'runs' => [], 'freshCopyConsumed' => false, 'images' => $seed['images'], 'effectiveDeployment' => $effective,
            'datasetDigest' => $seed['datasetDigest'], 'borrowedFrom' => ['stateDirectory' => $from, 'borrowerId' => $borrowerId]];
        $this->save($state);
        $seed['borrowers'][$this->directory] = ['borrowerId' => $borrowerId];
        AtomicFile::writeJson($from . '/qualification.json', $seed);
        AtomicFile::write($this->directory . '/dataset-manifest.json', $manifestText);
        $this->household()->render(HouseholdConfiguration::fromArray($effective));
        $childDeployment = $this->deploymentState();
        $sourceVolume = $seedDeployment['configuration']['volumes']['media'];
        $destinationVolume = $childDeployment['configuration']['volumes']['media'];
        if (!$this->ownedVolume($sourceVolume, $seedDeployment['owner']) || $sourceVolume === $destinationVolume
            || $this->docker->run(['volume', 'inspect', $destinationVolume], check: false)->exitCode === 0) {
            throw new \RuntimeException('Fixture copying requires an owned source and a fresh distinct destination volume.');
        }
        new ResourceLedger($this->directory . '/source-volume.json', $this->docker, $this->runtime->environment)->create(
            ResourceKind::Volume,
            $destinationVolume,
            ['--label', 'org.baander.household.owner=' . $childDeployment['owner'], '--label', 'org.baander.household=' . $this->configuration['name']],
        );
        $copy = DisposableContainer::run(
            $this->docker,
            $this->directory . '/fixture-copy-resources.json',
            [
                '--network', 'none', '--read-only', '--user', '0:0', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--memory', '256m', '--memory-swap', '256m', '--entrypoint', 'php',
                '--mount', 'type=volume,src=' . $sourceVolume . ',dst=/seed,readonly,volume-nocopy',
                '--mount', 'type=volume,src=' . $destinationVolume . ',dst=/destination,volume-nocopy',
                $seed['images']['app'], '-d', 'memory_limit=128M', '-r', self::copyProgram(), '/seed', '/destination',
            ],
            $this->runtime->environment,
            $this->runtime->configuration->budgets->seconds('cleanup'),
            input: json_encode(['files' => $manifest['files']], JSON_THROW_ON_ERROR),
            timeout: $this->configuration['fixtureTimeoutSeconds'],
        );
        $copyReceipt = json_decode($copy->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (($copyReceipt['verifiedMedia'] ?? null) !== 2300) {
            throw new \RuntimeException('Fixture copy did not verify all 2300 media files.');
        }
        AtomicFile::writeJson($this->directory . '/fixture-copy.json', $copyReceipt);
        $state['phase'] = 'prepared';
        $state['hostObservation'] = $this->docker->validateIdentity();
        if (($state['hostObservation']['MemTotal'] ?? PHP_INT_MAX) > $this->configuration['host']['constraintBytes']) {
            throw new \RuntimeException('Docker host exceeds the declared memory constraint.');
        }
        $state['preparation'] = ['outsideMeasurement' => true, 'sameImagesAndDatasetAs' => $from, 'filesystemCache' => 'Copying may warm the host page cache; cold means a fresh catalog, not cold filesystem cache.'];
        AtomicFile::writeJson($this->directory . '/deployment-configuration.json', $effective);
        $this->save($state);
        return $this->publicState($state);
    }

    public static function copyProgram(): string
    {
        return <<<'CODE'
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
[$source, $destination] = [$argv[1], $argv[2]];
if (count(scandir($destination)) !== 2) { throw new RuntimeException('Fixture destination is not empty.'); }
$verify = static function(string $root) use ($input): int {
    $count = 0;
    foreach ($input['files'] as $file) {
        $relative = $file['path'];
        if (!is_string($relative) || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) { throw new RuntimeException('Invalid fixture media path.'); }
        $path = $root . '/' . $relative;
        if (is_link($path) || !is_file($path) || filesize($path) !== $file['size'] || hash_file('xxh3', $path) !== $file['hash']) { throw new RuntimeException('Fixture media identity changed.'); }
        ++$count;
    }
    return $count;
};
$before = $verify($source);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$copied = 0;
$bytes = 0;
foreach ($files as $file) {
    if (++$copied > 10000 || $file->isLink()) { throw new RuntimeException('Fixture source contains excessive entries or a link.'); }
    $target = $destination . substr($file->getPathname(), strlen($source));
    if ($file->isDir()) { if (!mkdir($target, 0755)) { throw new RuntimeException('Cannot create fixture directory.'); } }
    elseif ($file->isFile()) { if (!copy($file->getPathname(), $target) || !chmod($target, 0644) || hash_file('sha256',$target) !== hash_file('sha256',$file->getPathname())) { throw new RuntimeException('Fixture copy differs.'); } $bytes += $file->getSize(); }
    else { throw new RuntimeException('Fixture source contains a special file.'); }
}
$after = $verify($destination);
echo json_encode(['verifiedMedia'=>$after,'sourceVerifiedMedia'=>$before,'copiedEntries'=>$copied,'copiedBytes'=>$bytes], JSON_THROW_ON_ERROR);
CODE;
    }

    /** @return array<string,mixed> */
    public function start(): array
    {
        $state = $this->state();
        if (!in_array($state['phase'], ['prepared', 'stopped'], true)) {
            throw new \RuntimeException('Start requires prepared or stopped qualification state.');
        }
        if (isset($state['provision'])) {
            unset($state['shutdown'], $state['shutdownOperatorMeasurements']);
            $this->household()->start(HouseholdConfiguration::fromArray($state['effectiveDeployment']));
            $state['phase'] = 'running';
            $this->save($state);
            return $this->publicState($state);
        }
        $directory = $this->directory . '/runs/cold-startup';
        PrivateDirectory::prepare($directory);
        $state['phase'] = 'starting';
        $state['activeRun'] = 'cold-startup';
        $this->save($state);
        $receipt = ['version' => 1, 'scenario' => 'cold-startup', 'failure' => null, 'cleanup' => []];
        $observer = null;
        $contract = null;
        try {
            $declaration = $this->contract($state, 'cold-startup', 0);
            $observer = $this->observer($state, $directory);
            $observer->start($this->configuration['maintenanceDeadlineSeconds'] + 600);
            $marker = $observer->update([], 'measured');
            $receipt['started'] = $marker;
            $contract = RunContract::fromArray(array_replace($declaration->toArray(), ['startedMonotonic' => $marker['monotonic']]));
            AtomicFile::writeJson($directory . '/contract.json', $contract->toArray());
            $receipt['operatorBoundary'] = $this->operatorInventory();
            $receipt['helperBoundary'] = $this->operatorInventory(true);
            $receipt['operatorRequiredActions'] = ['prepare' => 1, 'ipc-initialize' => 1, 'create' => 1, 'start' => 1, 'readiness' => 1];
            $receipt['nativeBefore'] = $this->verifyImageWrappers($state, $directory);
            $this->household()->start(HouseholdConfiguration::fromArray($state['effectiveDeployment']));
            $observer->update($this->roles(), 'measured');
            $users = [];
            for ($index = 0; $index < 6; ++$index) {
                $users[] = ['email' => $this->configuration['name'] . '-' . $index . '@baander.app', 'name' => 'Household ' . $index, 'password' => bin2hex(random_bytes(24))];
            }
            AtomicFile::writeJson($this->directory . '/users.json', $users);
            $application = new Application($this->docker, $this->deploymentState());
            $state['provision'] = $application->provision($this->dataset(), $users);
            $this->save($state);
            $receipt['scanDispatch'] = $application->startScan(array_column($state['provision']['libraries'], 'id'));
            $receipt['catalogIngestion'] = $this->waitMaintenance($state, $application, $observer, $directory, $marker, 0, null, false);
            $receipt['metadataDispatch'] = $application->startMetadataSync();
            $receipt['maintenance'] = $this->waitMaintenance($state, $application, $observer, $directory, $marker, self::COLD_MINIMUM_SECONDS);
            $receipt['nativeAfter'] = $this->verifyNativeWrappers($state);
            $state['playbackSlots'] = $application->playbackSlots($this->dataset());
            $state['phase'] = 'running';
        } catch (\Throwable $error) {
            $receipt['failure'] = $error->getMessage();
            try {
                $receipt['serviceStates'] = self::serviceStates($this->containers());
            } catch (\Throwable $inspection) {
                $receipt['serviceStates'] = null;
                $receipt['serviceStateFailure'] = $inspection->getMessage();
            }
            $state['phase'] = 'start-failed';
            throw $error;
        } finally {
            if ($observer !== null) {
                $receipt = $this->finishObservation($state, $observer, $directory, $receipt);
            }
            AtomicFile::writeJson($directory . '/receipt.json', $receipt);
            if ($contract !== null) {
                AtomicFile::writeJson($directory . '/evidence.json', $this->evidence($contract, $receipt));
            }
            $state['runs']['cold-startup'] = $directory;
            unset($state['activeRun']);
            $this->save($state);
        }
        return $this->publicState($state);
    }

    /** @return array<string,mixed> */
    public function scenario(string $scenario, int $repeat = 1): array
    {
        if (!in_array($scenario, ['original-overlap', 'fresh-copy-overlap', 'mixed-overlap'], true) || $repeat < 1 || $repeat > 3) {
            throw new \InvalidArgumentException('Select one overlap scenario and repeat 1, 2 or 3. Recovery and profile replacement use their separate owned lifecycle checks.');
        }
        $state = $this->state();
        if ($state['phase'] !== 'running' || !isset($state['provision'], $state['playbackSlots'])) {
            throw new \RuntimeException('Start and complete first import before an overlap scenario.');
        }
        if (($state['borrowers'] ?? []) !== []) {
            throw new \RuntimeException('A shared fixture seed cannot run source-mutating scenarios. Use a prepared child.');
        }
        $key = $scenario . '-' . $repeat;
        if (isset($state['runs'][$key]) || ($scenario === 'fresh-copy-overlap' && $state['freshCopyConsumed'])) {
            throw new \RuntimeException('Use a fresh qualification/deployment state for this repeat; fresh copy sources cannot be reused.');
        }
        $directory = $this->directory . '/runs/' . $key;
        PrivateDirectory::prepare($directory);
        $state['activeRun'] = $key;
        $state['phase'] = 'scenario-running';
        if ($scenario === 'fresh-copy-overlap') {
            $state['freshCopyConsumed'] = true;
        }
        $this->save($state);
        $observer = null;
        $maintenance = null;
        $browser = null;
        $browserConfiguration = null;
        $contract = null;
        $receipt = ['version' => 1, 'scenario' => $scenario, 'repeat' => $repeat, 'failure' => null, 'cleanup' => []];
        try {
            if ($scenario === 'fresh-copy-overlap' && $this->existingCopyJobs($state) !== []) {
                throw new \RuntimeException('Fresh-copy sources already have jobs; use a fresh deployment.');
            }
            $observer = $this->observer($state, $directory);
            $observer->start($this->configuration['maintenanceDeadlineSeconds'] + 600);
            $marker = $observer->update($this->roles(), 'measured');
            $contract = $this->contract($state, $scenario, $marker['monotonic']);
            AtomicFile::writeJson($directory . '/contract.json', $contract->toArray());
            $receipt['started'] = $marker;
            $receipt['operatorBoundary'] = $this->operatorInventory();
            $receipt['helperBoundary'] = $this->operatorInventory(true);
            $receipt['operatorRequiredActions'] = ['readiness' => 2];
            $this->operatorCheckpoint();
            $receipt['nativeBefore'] = $this->verifyNativeWrappers($state);
            $receipt['fileChange'] = $this->renameSource($state, $scenario, $repeat, $directory);
            $receipt['changedFileDiscoveryExercised'] = false;
            $maintenance = $this->startMaintenance($state, $directory);
            $receipt['maintenanceStartedBeforeBrowser'] = true;
            $browserConfiguration = $this->browserConfiguration($state, $scenario, $directory);
            AtomicFile::writeJson($directory . '/browser.json', $browserConfiguration);
            $browser = $this->runtime->start(
                [$this->configuration['nodeExecutable'], $this->runtime->root . '/ui/web/tests/e2e/household/driver.mjs', $directory . '/browser.json'],
                timeout: $this->configuration['maintenanceDeadlineSeconds'] + 300,
                logPath: $directory . '/browser.stdout',
                stderrLogPath: $directory . '/browser.stderr',
            );

            $this->runtime->await(function () use ($browser, $browserConfiguration): bool {
                if (!$this->runtime->alive($browser)) {
                    throw new \RuntimeException('Browser exited before both real players became ready.');
                }
                return is_file($browserConfiguration['readyPath']);
            }, 300, .1, 'Both browser players did not become ready.');
            $ready = AtomicFile::readJson($browserConfiguration['readyPath']);
            $receipt['browserReady'] = $ready;
            $application = new Application($this->docker, $this->deploymentState(), $state['provision']['libraries']);
            $readyMarker = $observer->update($this->roles(), 'measured');
            $receipt['maintenance'] = $this->waitMaintenance(
                $state,
                $application,
                $observer,
                $directory,
                $marker,
                $readyMarker['monotonic'] - $marker['monotonic'] + $this->configuration['durationSeconds'] + 3,
                $maintenance,
            );
            $receipt['changedFileDiscoveryExercised'] = $this->verifyRenamedSource($receipt['fileChange']);
            $receipt['maintenanceCommandExit'] = $this->runtime->wait($maintenance, false)->exitCode;
            if ($receipt['maintenanceCommandExit'] === 0) {
                $receipt['maintenanceCommandResults'] = self::parseMaintenanceLog($directory . '/maintenance.stdout');
                $dispatch = $receipt['maintenanceCommandResults'][3];
                if (($dispatch['jobsDispatched'] ?? null) !== 3 || !is_string($dispatch['jobId'] ?? null) || $dispatch['jobId'] === '') {
                    throw new \RuntimeException('Normal metadata synchronization did not dispatch the three fixture libraries.');
                }
            }
            if ($receipt['maintenanceCommandExit'] !== 0) {
                throw new \RuntimeException('Normal maintenance commands did not exit successfully.');
            }
            $until = Runtime::now() + max(0, $this->configuration['durationSeconds'] - $receipt['maintenance']['scan']['completedAtSeconds']) + 3;
            while (Runtime::now() < $until) {
                $observer->update($this->roles(), 'measured');
                $this->runtime->pause(1);
            }
            $browserResult = $this->runtime->wait($browser, false);
            $receipt['browserExitCode'] = $browserResult->exitCode;
            $receipt['browser'] = AtomicFile::readJson($browserConfiguration['outputPath'] . '.summary.json');
            $receipt['nativeAfter'] = $this->verifyNativeWrappers($state);
            $this->operatorCheckpoint();
            $receipt['freshCopyPlans'] = $scenario === 'fresh-copy-overlap' ? $this->freshCopyPlans($receipt['browser'], $marker['epochSeconds']) : [];
        } catch (\Throwable $error) {
            $receipt['failure'] = $error->getMessage();
            $state['phase'] = 'scenario-failed';
            if ($maintenance !== null && $this->runtime->alive($maintenance)) {
                $receipt['maintenanceCommandExit'] = $this->runtime->stop($maintenance)->exitCode;
            }
            if ($browser !== null && $browserConfiguration !== null && $this->runtime->alive($browser)) {
                AtomicFile::write($browserConfiguration['stopPath'], "stop\n");
                $this->runtime->wait($browser, false);
            }
        } finally {
            if ($observer !== null) {
                $receipt = $this->finishObservation($state, $observer, $directory, $receipt);
            }
            if ($scenario === 'fresh-copy-overlap' && $receipt['failure'] === null) {
                $receipt['copyMaintenanceOverlap'] = self::copyOverlapProof($directory, (float) ($receipt['maintenance']['metadata']['completedAtEpochSeconds'] ?? 0));
            }
            AtomicFile::writeJson($directory . '/receipt.json', $receipt);
            $state['runs'][$key] = $directory;
            if ($receipt['failure'] === null) {
                $state['phase'] = 'running';
            }
            unset($state['activeRun']);
            $this->save($state);
            if ($contract !== null) {
                AtomicFile::writeJson($directory . '/evidence.json', $this->evidence($contract, $receipt));
            }
        }
        return ['scenario' => $key, 'directory' => $directory, 'failure' => $receipt['failure'], 'qualificationClaim' => false];
    }

    /** @return array<string,mixed> */
    public function stop(bool $cleanup = false): array
    {
        $state = $this->state();
        if ($state['phase'] === 'cleaned') {
            return $this->report();
        }
        if ($cleanup) {
            self::assertBorrowersCleaned($state, $this->directory);
        }
        foreach (glob($this->directory . '/runs/*/observer-resources.json') ?: [] as $observerReceipt) {
            $recovered = ResourceLedger::recover($observerReceipt, $this->runtime->environment, $this->runtime->configuration->budgets->seconds('cleanup'));
            if (!$recovered->successful()) {
                throw new \RuntimeException('Observer recovery remains incomplete: ' . $observerReceipt);
            }
        }
        $deployment = $this->deploymentState();
        $neverStarted = in_array($state['phase'], ['preparing', 'prepared'], true);
        $started = time() - 1;
        $before = $this->containers();
        $operatorBoundary = $this->operatorInventory();
        try {
            if ($deployment['phase'] !== 'stopped') {
                $this->household()->stop();
            }
        } catch (\Throwable $error) {
            $state['shutdownOperatorMeasurements'] = $this->operatorMeasurements($operatorBoundary, ['status' => 1, 'recover' => 1]);
            $state['shutdownFailure'] = $error->getMessage();
            $this->save($state);
            foreach ($state['runs'] as $directory) {
                if (is_file($directory . '/evidence.json')) {
                    $evidence = AtomicFile::readJson($directory . '/evidence.json');
                    $evidence['operatorMeasurements']['shutdown'] = $state['shutdownOperatorMeasurements'];
                    AtomicFile::writeJson($directory . '/evidence.json', $evidence);
                }
            }
            throw $error;
        }
        $newShutdownOperators = $this->operatorMeasurements($operatorBoundary, ['status' => 1, 'recover' => 1]);
        $shutdownOperators = $state['shutdownOperatorMeasurements'] ?? $newShutdownOperators;
        if (isset($state['shutdownOperatorMeasurements']) && $newShutdownOperators['receipts'] !== []) {
            $shutdownOperators['receipts'] = [...$shutdownOperators['receipts'], ...$newShutdownOperators['receipts']];
            $shutdownOperators['until'] = max($shutdownOperators['until'], $newShutdownOperators['until']);
            $shutdownOperators['boundaryVerified'] = $shutdownOperators['boundaryVerified'] && $newShutdownOperators['boundaryVerified'];
        }
        $workers = new HouseholdStateStore($this->directory . '/deployment')->workers()->load();
        $retained = true;
        foreach ($deployment['configuration']['volumes'] as $volume) {
            $retained = $this->ownedVolume($volume, $deployment['owner']) && $retained;
        }
        $events = $this->docker->run(['events', '--since', (string) $started, '--until', (string) (time() + 1), '--filter', 'type=container', '--format', '{{json .}}'], timeout: 30)->stdout;
        AtomicFile::write($this->directory . '/shutdown-events.jsonl', $events);
        $normal = self::normalExits($before, $events);
        $shutdown = $state['shutdown'] ?? ['normalExit' => $normal, 'pairRetired' => self::pairRetired($workers), 'dataVolumesRetained' => $retained, 'fixturesRemoved' => false];
        if ($cleanup) {
            if (!$shutdown['pairRetired'] || (!$retained && !$neverStarted)) {
                throw new \RuntimeException('Fixture cleanup requires confirmed worker retirement and owned data-volume retention.');
            }
            $volumes = array_values(array_unique([...array_values($deployment['configuration']['volumes']), ...$deployment['configurationVolumes']]));
            foreach ($volumes as $volume) {
                if ($this->ownedVolume($volume, $deployment['owner'])) {
                    $this->docker->run(['volume', 'rm', $volume]);
                }
            }
            $source = new ResourceLedger($this->directory . '/source-volume.json', $this->docker, $this->runtime->environment)->cleanup($this->runtime->configuration->budgets->seconds('cleanup'));
            $imageReceipt = $state['images']['ownershipReceipt'] ?? $this->directory . '/images/image-ownership.json';
            $images = !isset($state['borrowedFrom']) && is_file($imageReceipt) ? new ImageLedger($imageReceipt, $this->docker)->cleanup($this->docker) : null;
            if (!$source->successful() || ($images !== null && !$images->successful())) {
                throw new \RuntimeException('Owned fixture resource cleanup is incomplete; retain receipts for recovery.');
            }
            $shutdown['fixturesRemoved'] = true;
        }
        $state['phase'] = $cleanup ? 'cleaned' : 'stopped';
        $state['shutdown'] = $shutdown;
        $state['shutdownOperatorMeasurements'] = $shutdownOperators;
        $this->save($state);
        foreach ($state['runs'] as $directory) {
            if (is_file($directory . '/evidence.json')) {
                $evidence = AtomicFile::readJson($directory . '/evidence.json');
                $evidence['cleanup'] = $shutdown;
                $evidence['operatorMeasurements']['shutdown'] = $shutdownOperators;
                AtomicFile::writeJson($directory . '/evidence.json', $evidence);
            }
        }
        return $this->report();
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $state = $this->state();
        $runs = [];
        foreach ($state['runs'] as $key => $directory) {
            if (is_file($directory . '/contract.json') && is_file($directory . '/evidence.json')) {
                $result = RunReport::evaluate(RunContract::fromArray(AtomicFile::readJson($directory . '/contract.json')), $directory . '/samples.jsonl', AtomicFile::readJson($directory . '/evidence.json'));
                AtomicFile::writeJson($directory . '/report.json', $result);
                $runs[$key] = $result;
            } else {
                $runs[$key] = ['status' => 'incomplete', 'receipt' => $directory . '/receipt.json'];
            }
        }
        return $this->publicState($state) + ['reports' => $runs, 'limits' => ['singleDeploymentOnly' => true, 'acceptedConfigurationRequiresThreeFreshRepeats' => true, 'recoveryProfileReplacementAndInsufficientMemoryRequireSeparateLifecycleEvidence' => true]];
    }

    /** The web server accepted a control request but returned no answer; a later request can still succeed. */
    public static function unansweredControlRequest(string $output): bool
    {
        return str_contains($output, 'the web server closed the control connection without answering')
            || preg_match('/the web server did not answer within [0-9.]+ seconds/', $output) === 1;
    }

    /**
     * Keeps the evidence that explains a failed start before teardown removes the containers.
     *
     * @param list<array<string,mixed>> $inspections
     * @return list<array<string,mixed>>
     */
    public static function serviceStates(array $inspections): array
    {
        return array_map(static fn(array $container): array => [
            'name' => ltrim((string) ($container['Name'] ?? ''), '/'),
            'service' => $container['Config']['Labels']['com.docker.compose.service'] ?? null,
            'running' => $container['State']['Running'] ?? null,
            'exitCode' => $container['State']['ExitCode'] ?? null,
            'oomKilled' => $container['State']['OOMKilled'] ?? null,
            'health' => $container['State']['Health']['Status'] ?? null,
            'memoryLimitBytes' => $container['HostConfig']['Memory'] ?? null,
            'healthLog' => array_map(
                static fn(array $probe): array => ['exitCode' => $probe['ExitCode'] ?? null, 'output' => substr((string) ($probe['Output'] ?? ''), 0, 512)],
                array_slice($container['State']['Health']['Log'] ?? [], -3),
            ),
        ], $inspections);
    }

    /**
     * The worker lifecycle saves its record before creating anything, so a missing record means no pair ever existed.
     *
     * @param array<string,mixed>|null $workers
     */
    public static function pairRetired(?array $workers): bool
    {
        return $workers === null || ($workers['phase'] ?? null) === 'retired';
    }

    /** @param list<array<string,mixed>> $containers */
    public static function normalExits(array $containers, string $events): bool
    {
        $expected = [];
        foreach ($containers as $container) {
            if (($container['State']['Running'] ?? false) && isset($container['Id'])) {
                $expected[$container['Id']] = false;
            }
        }
        foreach (explode("\n", trim($events)) as $line) {
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $id = $event['Actor']['ID'] ?? $event['id'] ?? null;
            if (($event['Action'] ?? $event['status'] ?? null) === 'die' && isset($expected[$id])) {
                $expected[$id] = in_array((string) ($event['Actor']['Attributes']['exitCode'] ?? ''), ['0', '143'], true);
            }
        }
        return $expected !== [] && !in_array(false, $expected, true);
    }

    /** @param array<string,mixed> $dataset
     * @return array<string,mixed>
     */
    public static function renamePlan(array $dataset, string $scenario, int $repeat, ?string $currentPath = null): array
    {
        $songs = array_values(array_filter($dataset['files'], static fn(array $file): bool => $file['kind'] === 'song'));
        if (count($songs) !== 2000 || !in_array($scenario, ['original-overlap', 'fresh-copy-overlap', 'mixed-overlap'], true) || $repeat < 1 || $repeat > 3) {
            throw new \InvalidArgumentException('A file change requires the complete dataset and a declared overlap.');
        }
        $song = $songs[1999];
        $extension = pathinfo($song['path'], PATHINFO_EXTENSION);
        $target = dirname($song['path']) . '/' . pathinfo($song['path'], PATHINFO_FILENAME) . '-' . $scenario . '-' . $repeat . '.' . $extension;
        $source = $currentPath ?? $song['path'];
        return ['kind' => 'rename-with-unchanged-content', 'originalPath' => $song['path'], 'source' => $source, 'target' => $target,
            'sourceSidecar' => substr($source, 0, -strlen($extension) - 1) . '.lrc', 'targetSidecar' => substr($target, 0, -strlen($extension) - 1) . '.lrc', 'expectedHash' => $song['hash']];
    }

    public static function renameProgram(): string
    {
        return <<<'CODE'
$change = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$root = $argv[1];
foreach (['source', 'target', 'sourceSidecar', 'targetSidecar'] as $key) {
    $relative = $change[$key];
    if (!is_string($relative) || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0")) { throw new RuntimeException('Invalid fixture path.'); }
    $change[$key] = $root . '/' . $relative;
}
foreach (['source', 'sourceSidecar'] as $key) { if (!is_file($change[$key]) || is_link($change[$key])) { throw new RuntimeException('Fixture source is missing or linked.'); } }
foreach (['target', 'targetSidecar'] as $key) { if (file_exists($change[$key]) || is_link($change[$key])) { throw new RuntimeException('Fixture rename target already exists.'); } }
$before = hash_file('xxh3', $change['source']);
if ($before !== $change['expectedHash']) { throw new RuntimeException('Fixture content changed before rename.'); }
if (!rename($change['source'], $change['target'])) { throw new RuntimeException('Cannot rename fixture media.'); }
if (!rename($change['sourceSidecar'], $change['targetSidecar'])) { rename($change['target'], $change['source']); throw new RuntimeException('Cannot rename fixture sidecar.'); }
$after = hash_file('xxh3', $change['target']);
if ($after !== $before || file_exists($change['source'])) { throw new RuntimeException('Fixture rename verification failed.'); }
echo json_encode(['beforeHash'=>$before,'afterHash'=>$after,'sourceAbsent'=>true,'sidecarMoved'=>true], JSON_THROW_ON_ERROR);
CODE;
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function renameSource(array &$state, string $scenario, int $repeat, string $directory): array
    {
        $change = self::renamePlan($this->dataset(), $scenario, $repeat, $state['changedSourcePath'] ?? null);
        $change['catalogBefore'] = $this->songAtPath($change['source']);
        $state['pendingRename'] = $change;
        $this->save($state);
        $measurement = $this->helperMeasurement('rename-source', $state['images']['app']);
        $memory = $state['effectiveDeployment']['resources']['operatorMemoryMiB'];
        $output = DisposableContainer::run($this->docker, $directory . '/file-change-resource.json', [
            '--network', 'none', '--read-only', '--user', '0:0', '--cap-drop', 'ALL', '--cgroupns', 'private',
            '--memory', $memory . 'm', '--memory-swap', $memory . 'm', '--entrypoint', 'php',
            '--mount', 'type=volume,src=' . $state['effectiveDeployment']['volumes']['media'] . ',dst=/source',
            $state['images']['app'], '-d', 'memory_limit=64M', '-r', $measurement['bootstrap'] . "\n" . self::renameProgram(), '/source',
        ], $this->runtime->environment, $this->runtime->configuration->budgets->seconds('cleanup'), input: json_encode($change, JSON_THROW_ON_ERROR), timeout: 30, onCompletion: $measurement['onCompletion'])->stdout;
        $change['filesystem'] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $state['changedSourcePath'] = $change['target'];
        unset($state['pendingRename']);
        $this->save($state);
        AtomicFile::writeJson($directory . '/file-change.json', $change);
        return $change;
    }

    /** @param array<string,mixed> $change */
    private function verifyRenamedSource(array $change): bool
    {
        $after = $this->songAtPath($change['target']);
        if ($after['publicId'] !== $change['catalogBefore']['publicId'] || $after['hash'] !== $change['catalogBefore']['hash']) {
            throw new \RuntimeException('Scanned rename did not preserve the song identity and content hash.');
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function songAtPath(string $relative): array
    {
        $deployment = $this->deploymentState();
        $environment = $deployment['configuration']['environment'];
        $path = str_replace("'", "''", '/storage/media/' . $relative);
        $sql = "SELECT coalesce(jsonb_agg(jsonb_build_object('publicId', public_id, 'hash', hash, 'path', path)), '[]'::jsonb) FROM songs WHERE path = '{$path}'";
        $rows = json_decode(trim($this->docker->compose($deployment['configuration']['name'], $deployment['composeFile'], ['exec', '-T', 'postgres', 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', $environment['DB_USERNAME'], '-d', $environment['DB_DATABASE']], input: $sql)->stdout), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($rows) || count($rows) !== 1) {
            throw new \RuntimeException('Expected exactly one owned fixture song at the changed path.');
        }
        return $rows[0];
    }

    /** @return array<string,mixed> */
    public static function copyOverlapProof(string $directory, float $metadataCompletedEpoch): array
    {
        $output = @file_get_contents($directory . '/maintenance.stdout');
        if (!is_string($output) || preg_match('/^BAANDER_MAINTENANCE_START:([0-9.]+)$/m', $output, $start) !== 1
            || preg_match('/^BAANDER_MAINTENANCE_FINISH:([0-9.]+)$/m', $output, $end) !== 1) {
            return ['observed' => false, 'reason' => 'Maintenance command timestamps are missing.'];
        }
        $from = (float) $start[1];
        $commandFinished = (float) $end[1];
        if (!is_finite($metadataCompletedEpoch) || $metadataCompletedEpoch < $commandFinished || $commandFinished < $from) {
            return ['observed' => false, 'reason' => 'Observed metadata completion is missing or precedes command dispatch.'];
        }
        $lastPending = null;
        $database = @fopen($directory . '/database.jsonl', 'rb');
        if ($database !== false) {
            try {
                while (($line = fgets($database, 1048577)) !== false) {
                    $observation = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $epoch = $observation['epochSeconds'] ?? null;
                    if (!is_numeric($epoch) || $epoch < $from || $epoch >= $metadataCompletedEpoch) {
                        continue;
                    }
                    foreach ($observation['snapshot']['jobs']['groups'] ?? [] as $group) {
                        if (in_array($group['name'] ?? null, ['SyncLibraryMessage', 'SyncAlbumMessage', 'SyncSongMessage'], true)
                            && in_array($group['status'] ?? null, ['queued', 'running'], true) && ($group['count'] ?? 0) > 0) {
                            $lastPending = max($lastPending ?? $from, (float) $epoch);
                        }
                    }
                }
            } finally {
                fclose($database);
            }
        }
        if ($lastPending === null) {
            return ['observed' => false, 'reason' => 'Pending or running metadata work was not observed before completion.'];
        }
        $until = $lastPending;
        $launches = 0;
        $native = @fopen($directory . '/native.jsonl', 'rb');
        if ($native !== false) {
            while (($line = fgets($native, 65537)) !== false) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (($event['at'] ?? 0) >= $from && $event['at'] <= $until
                    && NativeAudit::classify($event['executable'], $event['arguments']) === 'copy') {
                    ++$launches;
                }
            }
            fclose($native);
        }
        $peak = 0;
        $samples = @fopen($directory . '/samples.jsonl', 'rb');
        if ($samples !== false) {
            while (($line = fgets($samples, HostSampler::MAX_LINE_BYTES + 1)) !== false) {
                $sample = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (($sample['epochSeconds'] ?? 0) < $from || $sample['epochSeconds'] > $until) {
                    continue;
                }
                $copies = 0;
                foreach ($sample['processes'] as $process) {
                    if (str_starts_with(basename($process['executable'] ?? ''), 'ffmpeg')
                        && preg_match('/(?:^|\s)-(?:c|codec)(?::[av](?::[0-9]+)?)?\s+copy(?:\s|$)/', $process['command'] ?? '') === 1) {
                        ++$copies;
                    }
                }
                $peak = max($peak, $copies);
            }
            fclose($samples);
        }
        return ['observed' => $launches >= 2, 'copyLaunchesDuringMaintenance' => $launches,
            'sampledSimultaneousCopyProcessesLowerBound' => $peak, 'maintenanceStartedEpoch' => $from,
            'maintenanceCommandFinishedEpoch' => $commandFinished, 'maintenanceFinishedEpoch' => $metadataCompletedEpoch,
            'lastPendingMetadataEpoch' => $lastPending];
    }

    /** @return list<array<string,mixed>> */
    public static function parseMaintenanceLog(string $path): array
    {
        $content = file_get_contents($path, length: 1048577);
        if ($content === false || strlen($content) > 1048576) {
            throw new \RuntimeException('Normal maintenance output is missing or exceeds its bounded receipt.');
        }
        $content = preg_replace('/^BAANDER_MAINTENANCE_(?:START|FINISH):[0-9.]+\R/m', '', $content);
        $documents = explode('BAANDER_MAINTENANCE_RESULT_END', $content);
        if (count($documents) !== 5 || trim(array_pop($documents)) !== '') {
            throw new \RuntimeException('Normal maintenance output did not contain all four command receipts.');
        }
        return array_map(static function (string $document): array {
            $value = json_decode(trim($document), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                throw new \RuntimeException('Invalid maintenance command JSON.');
            }
            return $value;
        }, $documents);
    }

    /** @param array<string,mixed> $state */
    private function startMaintenance(array $state, string $directory): RunningProcess
    {
        $deployment = $this->deploymentState();
        $home = ApplicationLayout::fromArray($deployment['layout']);
        $commands = ['set -eu', "printf 'BAANDER_MAINTENANCE_START:%s\\n' \"$(date +%s.%N)\""];
        foreach ($state['provision']['libraries'] as $library) {
            $id = $library['publicId'];
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) {
                throw new \RuntimeException('Invalid owned library identifier.');
            }
            $commands[] = 'php ' . escapeshellarg($home->path('bin/console')) . ' app:library:scan ' . escapeshellarg($id) . ' --json --no-interaction';
            $commands[] = "printf '\\nBAANDER_MAINTENANCE_RESULT_END\\n'";
        }
        $commands[] = 'php ' . escapeshellarg($home->path('bin/console')) . ' app:metadata:sync --json --no-interaction';
        $commands[] = "printf '\\nBAANDER_MAINTENANCE_RESULT_END\\n'";
        $commands[] = "printf 'BAANDER_MAINTENANCE_FINISH:%s\\n' \"$(date +%s.%N)\"";
        return $this->docker->start(
            ['compose', '--project-name', $deployment['configuration']['name'], '--file', $deployment['composeFile'], 'exec', '-T', 'web', 'timeout', '--signal=TERM', '--kill-after=10s', (string) $this->configuration['maintenanceDeadlineSeconds'], 'sh', '-ec', implode("\n", $commands)],
            timeout: $this->configuration['maintenanceDeadlineSeconds'],
            logPath: $directory . '/maintenance.stdout',
            stderrLogPath: $directory . '/maintenance.stderr',
        );
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function browserConfiguration(array $state, string $scenario, string $directory): array
    {
        $catalog = $state['playbackSlots'];
        $selected = match ($scenario) {
            'original-overlap' => $catalog['originalVideos'],
            'fresh-copy-overlap' => $catalog['copyVideos'],
            'mixed-overlap' => [$catalog['music'], $catalog['originalVideos'][0]],
            default => throw new \InvalidArgumentException('Unknown browser workload.'),
        };
        $users = AtomicFile::readJson($this->directory . '/users.json');
        $offset = array_search($scenario, ['original-overlap', 'fresh-copy-overlap', 'mixed-overlap'], true) * 2;
        $slots = [];
        foreach ($selected as $index => $item) {
            $audio = isset($item['songPublicId']);
            $slots[] = ['kind' => $audio ? 'audio' : 'video', 'publicId' => $audio ? $item['songPublicId'] : $item['videoPublicId'],
                'pagePath' => $audio ? '/music/albums/' . $item['albumPublicId'] : '/movies/' . $item['moviePublicId'], 'songTitle' => $item['title'],
                'expectedDelivery' => $scenario === 'fresh-copy-overlap' ? 'copy' : 'original',
                'email' => $users[$offset + $index]['email'], 'password' => $users[$offset + $index]['password']];
        }
        return ['baseURL' => $state['effectiveDeployment']['origin'], 'scenario' => $scenario, 'durationSeconds' => $this->configuration['durationSeconds'],
            'slots' => $slots, 'outputPath' => $directory . '/browser.jsonl', 'readyPath' => $directory . '/browser-ready.json', 'stopPath' => $directory . '/browser-stop',
            'startupTimeoutSeconds' => 180] + (isset($this->configuration['chromiumExecutablePath']) ? ['chromiumExecutablePath' => $this->configuration['chromiumExecutablePath']] : []);
    }

    /** @param array<string,mixed> $state */
    private function contract(array $state, string $scenario, float $started): RunContract
    {
        $deployment = $this->deploymentState();
        $profile = ImageProfile::fromArray($deployment['profile']);
        $limits = [];
        foreach (['web', 'redis', 'gateway'] as $role) {
            $limits[$role] = ['memoryMaxBytes' => $state['effectiveDeployment']['resources'][$role . 'MemoryMiB'] * 1024 ** 2];
        }
        // PostgreSQL runs without a container memory limit.
        $limits['postgres'] = ['memoryMaxBytes' => 'max'];
        $limits['control'] = ['memoryMaxBytes' => $profile->controlMemoryMiB * 1024 ** 2];
        $limits['execution'] = ['memoryMaxBytes' => $profile->executionMemoryMiB * 1024 ** 2];
        $limits['observer'] = ['memoryMaxBytes' => $this->configuration['observerMemoryMiB'] * 1024 ** 2];
        return RunContract::fromArray(['version' => 1, 'scenario' => $scenario, 'profile' => $deployment['configuration']['profile'], 'imageId' => $state['images']['app'],
            'startedMonotonic' => $started, 'sampleIntervalSeconds' => 1, 'maxSampleGapSeconds' => 3, 'durationSeconds' => $scenario === 'cold-startup' ? self::COLD_MINIMUM_SECONDS : $this->configuration['durationSeconds'],
            'maintenanceDeadlineSeconds' => $this->configuration['maintenanceDeadlineSeconds'], 'requiredRoles' => array_keys($limits), 'configuredLimits' => $limits,
            'dataset' => ['songs' => 2000, 'movies' => 50, 'episodes' => 250, 'preparedBeforeRun' => true, 'fixtureDigest' => $state['datasetDigest']],
            'schedule' => [['atSeconds' => 0, 'action' => $scenario === 'cold-startup' ? 'start-stack-provision-and-normal-scan' : 'start-normal-scan-before-browser'], ['atSeconds' => 0, 'action' => 'normal-metadata-sync-after-scan']],
            'host' => $this->configuration['host'],
            'initialConditions' => ['catalog' => $scenario === 'cold-startup' ? 'empty-catalog-first-import' : 'previously-imported-catalog',
                'filesystemCache' => 'not-cleared-generation-may-have-warmed-page-cache', 'overlayDigest' => $state['images']['manifestDigest'],
                'sourceRevision' => $state['images']['manifest']['sourceRevision']],
            'operatorMemoryMiB' => $state['effectiveDeployment']['resources']['operatorMemoryMiB'],
            'deploymentOwner' => $deployment['owner'],
            'workerNamespace' => $state['effectiveDeployment']['name'], 'operatorImageId' => $state['images']['operator'], 'workerImageId' => $state['images']['worker']]);
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function evidence(RunContract $contract, array $receipt): array
    {
        $playback = [];
        foreach ($receipt['browser']['slots'] ?? [] as $slot) {
            $playback[] = ['id' => (string) $slot['index'], 'observedSeconds' => $slot['measuredSeconds'], 'progressSeconds' => $slot['totalPositionAdvanceSeconds'],
                'progressObservations' => $slot['samples'], 'stalls' => ($slot['passed'] ?? false) ? 0 : 1];
        }
        $failures = [];
        if (($receipt['metadataProof']['complete'] ?? false) !== true) {
            $failures[] = 'Scenario-scoped metadata refresh requests were not completely verified.';
        }
        if ($contract->toArray()['scenario'] === 'fresh-copy-overlap' && ($receipt['copyMaintenanceOverlap']['observed'] ?? false) !== true) {
            $failures[] = 'Two fresh copy launches during maintenance were not observed.';
        }
        if ($contract->requiresPlayback() && ($receipt['changedFileDiscoveryExercised'] ?? false) !== true) {
            $failures[] = 'Changed-file discovery was not verified.';
        }
        if ($receipt['failure'] !== null || (($receipt['browser']['passed'] ?? true) !== true)) {
            $failures[] = $receipt['failure'] ?? 'Browser playback did not pass.';
        }
        return ['contractFingerprint' => $contract->fingerprint(), 'completedMonotonic' => $receipt['completedMonotonic'] ?? null, 'playback' => $playback,
            'queues' => $receipt['maintenance'] ?? [], 'operatorMeasurements' => ['workload' => $receipt['operatorMeasurements'] ?? null], 'helperMeasurements' => $receipt['helperMeasurements'] ?? null, 'metadataProof' => $receipt['metadataProof'] ?? null, 'nativeAudit' => $receipt['nativeAudit'] ?? ['complete' => false, 'forbiddenLaunches' => null],
            'unexpectedExits' => [...$failures, ...($receipt['unexpectedExits'] ?? [])], 'cleanup' => ['normalExit' => false, 'pairRetired' => false, 'dataVolumesRetained' => false, 'fixturesRemoved' => false]];
    }

    /** @return array<string,string> */
    private function operatorInventory(bool $helpers = false): array
    {
        $files = [];
        foreach (glob($this->directory . ($helpers ? '/deployment/helper-measurements/*/*.json' : '/deployment/workers/*/operator-measurements/*.json')) ?: [] as $path) {
            PrivateDirectory::assertNoSymlinks($path);
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new \RuntimeException('Cannot identify operator measurement receipt.');
            }
            $files[substr($path, strlen($this->directory) + 1)] = $hash;
        }
        if (count($files) > 4096) {
            throw new \RuntimeException('Operator measurement inventory exceeds its bound.');
        }
        return $files;
    }

    /** @param array<string,string>|null $boundary
     * @param array<string,int> $requiredActions
     * @return array<string,mixed>
     */
    private function operatorMeasurements(?array $boundary, array $requiredActions, ?float $from = null, ?float $until = null, bool $helpers = false): array
    {
        $inventory = $this->operatorInventory($helpers);
        $errors = [];
        if ($boundary === null) {
            $errors[] = 'No operator measurement boundary was captured by this run.';
            $boundary = $inventory;
        }
        foreach ($boundary as $path => $hash) {
            if (($inventory[$path] ?? null) !== $hash) {
                $errors[] = 'A pre-existing operator receipt changed or disappeared.';
            }
        }
        $receipts = [];
        foreach (array_diff_key($inventory, $boundary) as $relative => $hash) {
            $path = $this->directory . '/' . $relative;
            if (filesize($path) > 65536) {
                $errors[] = 'Operator receipt exceeds its bound.';
                continue;
            }
            $document = AtomicFile::readJson($path);
            $pattern = $helpers ? '~/helper-measurements/([^/]+)/([a-z-]+)-([a-f0-9]{32})\.json$~D' : '~/([^/]+)/operator-measurements/([a-z-]+)-([a-f0-9]{32})\.json$~D';
            if (preg_match($pattern, $relative, $match) !== 1
                || ($document['bootId'] ?? null) !== $match[1] || ($document['action'] ?? null) !== $match[2] || ($document['nonce'] ?? null) !== $match[3]) {
                $errors[] = 'Operator receipt path does not bind its invocation identity.';
            }
            $document['receiptPath'] = $relative;
            $document['receiptSha256'] = $hash;
            $receipts[] = $document;
        }
        $worker = new HouseholdStateStore($this->directory . '/deployment')->workers()->load();
        $deployment = $this->deploymentState();
        $times = array_values(array_filter(array_column($receipts, 'startedMonotonic'), static fn(mixed $value): bool => is_int($value) || is_float($value)));
        $ends = array_values(array_filter(array_column($receipts, 'finishedMonotonic'), static fn(mixed $value): bool => is_int($value) || is_float($value)));
        $context = ['namespace' => $deployment['configuration']['name'], 'bootId' => $helpers ? $deployment['owner'] : ($worker['boot_id'] ?? ''),
            'operatorImage' => $deployment['images']['operator']['id'] ?? null, 'workerImage' => $deployment['images']['worker']['id'] ?? null,
            'memoryMaxBytes' => $deployment['configuration']['resources']['operatorMemoryMiB'] * 1024 ** 2];
        if ($helpers) {
            $context['actionImages'] = array_map(static fn(string $role): string => $deployment['images'][$role]['id'] ?? throw new \RuntimeException('Helper image identity is unavailable.'), Household::helperRoles(true) + Household::helperRoles(false));
        }
        return ['boundaryVerified' => $errors === [], 'collectionErrors' => $errors, 'receipts' => $receipts, 'requiredActions' => $requiredActions,
            'from' => $from ?? ($times === [] ? 0.0 : min($times)), 'until' => $until ?? ($ends === [] ? 0.0 : max($ends)),
            'context' => $context];
    }

    /** @return array{bootstrap:string,onCompletion:\Closure,path:string} */
    private function helperMeasurement(string $action, string $image): array
    {
        $deployment = $this->deploymentState();
        return ContainerMeasurement::prepare(['directory' => $this->directory . '/deployment/helper-measurements/' . $deployment['owner'],
            'namespace' => $deployment['configuration']['name'], 'boot_id' => $deployment['owner']], $action, $image);
    }

    private function operatorCheckpoint(): void
    {
        $store = new HouseholdStateStore($this->directory . '/deployment')->workers();
        $worker = $store->load() ?? throw new \RuntimeException('Worker operator readiness checkpoint has no saved deployment.');
        $result = new WorkerLifecycle($store, $this->runtime->executor, $this->runtime->environment)->invoke($worker, 'readiness');
        if (($result['readiness'] ?? null) !== 'ready') {
            throw new \RuntimeException('Worker operator readiness checkpoint did not pass.');
        }
    }

    /** @param array<string,mixed> $state
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function finishObservation(array $state, Observer $observer, string $directory, array $receipt): array
    {
        try {
            $completed = $observer->update($this->roles(), 'measured');
            $receipt['completedMonotonic'] = $completed['monotonic'];
            $receipt['completedEpochSeconds'] = $completed['epochSeconds'];
        } catch (\Throwable $error) {
            $receipt['observerError'] = $error->getMessage();
        }
        try {
            $receipt['observer'] = $observer->stop();
            if (!is_file($directory . '/host-raw.jsonl')) {
                throw new \RuntimeException('Observer did not produce a sample stream.');
            }
            Observer::extractPhase($directory . '/host-raw.jsonl', $directory . '/samples.jsonl', 'measured');
            $input = fopen($directory . '/samples.jsonl', 'rb');
            if ($input !== false) {
                while (($line = fgets($input, HostSampler::MAX_LINE_BYTES + 1)) !== false) {
                    $sample = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $receipt['completedMonotonic'] = max($receipt['completedMonotonic'] ?? 0, $sample['monotonic']);
                }
                fclose($input);
            }
        } catch (\Throwable $error) {
            $receipt['observerError'] = $error->getMessage();
        }
        try {
            $receipt['nativeAudit'] = $this->nativeJournal($state, $directory, $receipt['nativeBefore'] ?? false, $receipt['nativeAfter'] ?? false);
        } catch (\Throwable $error) {
            $receipt['nativeAudit'] = ['complete' => false, 'forbiddenLaunches' => null, 'errors' => [$error->getMessage()]];
        }
        try {
            $receipt['operatorMeasurements'] = $this->operatorMeasurements(
                $receipt['operatorBoundary'] ?? null,
                $receipt['operatorRequiredActions'] ?? [],
                $receipt['started']['monotonic'] ?? 0,
                $receipt['completedMonotonic'] ?? 0,
            );
        } catch (\Throwable $error) {
            $receipt['operatorMeasurements'] = ['boundaryVerified' => false, 'collectionErrors' => [$error->getMessage()]];
        }
        try {
            $required = array_fill_keys(array_keys(Household::helperRoles(($receipt['scenario'] ?? null) === 'cold-startup')), 1);
            $receipt['helperMeasurements'] = $this->operatorMeasurements(
                $receipt['helperBoundary'] ?? null,
                $required,
                $receipt['started']['monotonic'] ?? 0,
                $receipt['completedMonotonic'] ?? 0,
                true,
            );
        } catch (\Throwable $error) {
            $receipt['helperMeasurements'] = ['boundaryVerified' => false, 'collectionErrors' => [$error->getMessage()]];
        }
        try {
            $receipt['metadataProof'] = $this->metadataProof($state, $directory, $receipt['started']['epochSeconds'] ?? 0, $receipt['completedEpochSeconds'] ?? 0);
        } catch (\Throwable $error) {
            $receipt['metadataProof'] = ['complete' => false, 'errors' => [$error->getMessage()]];
        }
        $receipt['unexpectedExits'] = array_values(array_unique($this->unexpectedExits));
        $receipt['webControlRetries'] = $this->webControlRetries;
        if (($receipt['observer']['normalExit'] ?? false) !== true) {
            $receipt['unexpectedExits'][] = 'Observer did not exit normally.';
        }
        if (isset($receipt['observerError'])) {
            $receipt['unexpectedExits'][] = $receipt['observerError'];
        }
        return $receipt;
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function metadataProof(array $state, string $directory, float $from, float $until): array
    {
        $web = array_find($this->containers(), static fn(array $container): bool => ($container['Config']['Labels']['com.docker.compose.service'] ?? null) === 'web');
        if ($web === null || !isset($state['images']['metadataAuditPath'])) {
            return ['complete' => false, 'errors' => ['Metadata audit source unavailable.']];
        }
        $path = $directory . '/metadata.jsonl';
        $result = $this->docker->run(['cp', $web['Id'] . ':' . $state['images']['metadataAuditPath'], $path], check: false);
        if ($result->exitCode !== 0) {
            return ['complete' => false, 'errors' => ['Metadata journal copy failed.']];
        }
        return self::metadataCoverage($path, $this->dataset(), $from, $until);
    }

    /** @param array<string,mixed> $dataset
     * @return array<string,mixed>
     */
    public static function metadataCoverage(string $path, array $dataset, float $from, float $until): array
    {
        $required = array_fill_keys(array_values($dataset['albums']), 'album');
        foreach ($dataset['files'] as $file) {
            if ($file['kind'] === 'song') {
                $required[$file['metadata']['id']] = 'song';
            }
        }
        $seen = [];
        $errors = [];
        $requests = 0;
        if ($until < $from || !is_file($path) || filesize($path) > 128 * 1024 ** 2) {
            return ['complete' => false, 'errors' => ['Invalid metadata journal or observation window.']];
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return ['complete' => false, 'errors' => ['Metadata journal unavailable.']];
        }
        try {
            while (($line = fgets($stream, 65537)) !== false) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!str_ends_with($line, "\n") || !is_numeric($event['at'] ?? null)) {
                    throw new \RuntimeException('Invalid metadata journal record.');
                }
                if ($event['at'] < $from || $event['at'] > $until) {
                    continue;
                }
                ++$requests;
                if (($event['outcome'] ?? null) !== 'matched') {
                    $errors[] = 'Unmatched metadata request within observation window.';
                    continue;
                }
                $id = $event['identity'] ?? null;
                if (is_string($id) && isset($required[$id])) {
                    $seen[$id] = true;
                }
            }
        } finally {
            fclose($stream);
        }
        $missing = array_diff_key($required, $seen);
        return ['complete' => $missing === [] && $errors === [], 'fromEpochSeconds' => $from, 'untilEpochSeconds' => $until,
            'requests' => $requests, 'requiredIdentities' => count($required), 'matchedIdentities' => count($seen),
            'missingIdentities' => array_keys($missing), 'errors' => array_values(array_unique($errors)), 'journalSha256' => hash_file('sha256', $path)];
    }

    /** @param array<string,mixed> $state */
    private function verifyImageWrappers(array $state, string $directory): bool
    {
        $program = 'echo json_encode(["ffmpeg"=>hash_file("sha256","/usr/bin/ffmpeg"),"ffprobe"=>hash_file("sha256","/usr/bin/ffprobe")],JSON_THROW_ON_ERROR);';
        foreach (['app', 'worker', 'operator'] as $role) {
            $measurement = $this->helperMeasurement('verify-wrappers-' . ($role === 'app' ? 'application' : $role), $state['images'][$role]);
            $memory = $state['effectiveDeployment']['resources']['operatorMemoryMiB'];
            $output = DisposableContainer::run(
                $this->docker,
                $directory . '/wrapper-' . $role . '.json',
                ['--network', 'none', '--read-only', '--cgroupns', 'private', '--memory', $memory . 'm', '--memory-swap', $memory . 'm', '--entrypoint', 'php', $state['images'][$role], '-r', $measurement['bootstrap'] . "\n" . $program],
                $this->runtime->environment,
                $this->runtime->configuration->budgets->seconds('cleanup'),
                timeout: 30,
                onCompletion: $measurement['onCompletion'],
            )->stdout;
            if (json_decode($output, true, flags: JSON_THROW_ON_ERROR) != $state['images']['wrapperHashes']) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $state */
    private function verifyNativeWrappers(array $state): bool
    {
        $seen = [];
        foreach ($this->containers() as $container) {
            $labels = $container['Config']['Labels'] ?? [];
            if (($labels['com.docker.compose.service'] ?? null) !== 'web' && !isset($labels['app.baander.worker.role'])) {
                continue;
            }
            $seen[] = $labels['com.docker.compose.service'] ?? $labels['app.baander.worker.role'];
            $program = 'echo json_encode(["ffmpeg"=>hash_file("sha256","/usr/bin/ffmpeg"),"ffprobe"=>hash_file("sha256","/usr/bin/ffprobe")],JSON_THROW_ON_ERROR);';
            $hashes = json_decode($this->docker->run(['exec', $container['Id'], 'php', '-r', $program])->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($hashes != $state['images']['wrapperHashes']) {
                return false;
            }
        }
        sort($seen);
        return $seen === ['control', 'execution', 'web'];
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function nativeJournal(array $state, string $directory, bool $before, bool $after): array
    {
        $web = array_find($this->containers(), static fn(array $container): bool => ($container['Config']['Labels']['com.docker.compose.service'] ?? null) === 'web');
        if ($web === null) {
            return ['complete' => false, 'forbiddenLaunches' => null, 'errors' => ['Web container unavailable for native audit.']];
        }
        $path = $directory . '/native.jsonl';
        $copy = $this->docker->run(['cp', $web['Id'] . ':' . $state['images']['nativeAuditPath'], $path], check: false);
        return NativeAudit::read($path, $before && $copy->exitCode === 0, $after, $state['effectiveDeployment']['profile']);
    }

    /** @param array<string,mixed> $state
     * @return list<string>
     */
    private function existingCopyJobs(array $state): array
    {
        $ids = [];
        foreach ($state['playbackSlots']['copyVideos'] as $video) {
            if (!is_string($video['videoPublicId'] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $video['videoPublicId']) !== 1) {
                throw new \RuntimeException('Invalid fixture video identity.');
            }
            $ids[] = "'" . $video['videoPublicId'] . "'";
        }
        $deployment = $this->deploymentState();
        $environment = $deployment['configuration']['environment'];
        $sql = "SELECT coalesce(jsonb_agg(j.public_id), '[]'::jsonb) FROM transcode_jobs j JOIN videos v ON v.id=j.video_id WHERE v.public_id IN (" . implode(',', $ids) . ")";
        return json_decode(trim($this->docker->compose($deployment['configuration']['name'], $deployment['composeFile'], ['exec', '-T', 'postgres', 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', $environment['DB_USERNAME'], '-d', $environment['DB_DATABASE']], input: $sql)->stdout), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $browser
     * @return list<array<string,mixed>>
     */
    private function freshCopyPlans(array $browser, float $started): array
    {
        $ids = [];
        foreach ($browser['slots'] ?? [] as $slot) {
            foreach ($slot['observedDecisions'] ?? [] as $decision) {
                if (($decision['kind'] ?? null) !== 'copy' || !is_string($decision['jobId'] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $decision['jobId']) !== 1) {
                    throw new \RuntimeException('Fresh copy requires observed owned copy job identifiers.');
                }
                $ids[] = $decision['jobId'];
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) !== 2) {
            throw new \RuntimeException('Fresh copy requires two distinct owned jobs.');
        }
        $list = implode(',', array_map(static fn(string $id): string => "'" . $id . "'", $ids));
        $sql = "SELECT coalesce(jsonb_agg(jsonb_build_object('id',j.public_id,'created',extract(epoch from j.created_at),'selection',s.desired->'presentationSelection')), '[]'::jsonb) FROM transcode_jobs j JOIN transcode_runtime_state s ON s.job_id=j.id WHERE j.public_id IN ({$list})";
        $deployment = $this->deploymentState();
        $environment = $deployment['configuration']['environment'];
        $plans = json_decode(trim($this->docker->compose($deployment['configuration']['name'], $deployment['composeFile'], ['exec', '-T', 'postgres', 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', $environment['DB_USERNAME'], '-d', $environment['DB_DATABASE']], input: $sql)->stdout), true, flags: JSON_THROW_ON_ERROR);
        if (count($plans) !== 2 || array_any($plans, static fn(array $plan): bool => ($plan['selection']['mode'] ?? null) !== 'copy' || ($plan['created'] ?? 0) < $started - 300)) {
            throw new \RuntimeException('Copy jobs are stale or their persisted plans permit encoding.');
        }
        return $plans;
    }

    /** @return list<array<string,mixed>> */
    private function containers(): array
    {
        $deployment = $this->deploymentState();
        $ids = [];
        foreach (['org.baander.household.owner=' . $deployment['owner'], 'app.baander.worker.namespace=' . $this->configuration['name']] as $label) {
            $ids = [...$ids, ...(preg_split('/\s+/', trim($this->docker->run(['ps', '--all', '--quiet', '--no-trunc', '--filter', 'label=' . $label])->stdout), flags: PREG_SPLIT_NO_EMPTY) ?: [])];
        }
        return $ids === [] ? [] : json_decode($this->docker->run(['inspect', ...array_values(array_unique($ids))])->stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    private function ownedVolume(string $name, string $owner): bool
    {
        $inspection = $this->docker->run(['volume', 'inspect', $name], check: false);
        if ($inspection->exitCode !== 0) {
            return false;
        }
        $volumes = json_decode($inspection->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (($volumes[0]['Labels']['org.baander.household.owner'] ?? null) !== $owner) {
            throw new \RuntimeException('Refusing foreign fixture volume: ' . $name);
        }
        return true;
    }

    /** @param array<string,mixed> $dataset */
    public static function datasetDigest(array $dataset): string
    {
        $files = array_map(static function (array $file): array {
            $identity = array_intersect_key($file, array_flip(['path', 'hash', 'size', 'duration', 'videoCodec', 'audioCodec']));
            ksort($identity, SORT_STRING);
            return $identity;
        }, $dataset['files']);
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $snapshot
     * @param array<string,mixed> $queues
     * @return array<string,mixed>
     */
    public static function maintenanceEvidence(array $snapshot, array $queues, float $elapsed): array
    {
        $scan = true;
        $metadata = true;
        foreach (['music' => 2000, 'movies' => 50, 'episodes' => 250] as $role => $count) {
            $library = $snapshot['libraries'][$role] ?? [];
            $scan = $scan && ($library['exists'] ?? false) === true && ($library['scanStatus'] ?? null) === 'completed'
                && ($library['missingCatalogFiles'] ?? null) === 0
                && ($library['indexedAvailableFiles'] ?? null) === $count
                && ($library['indexedFiles'] ?? null) === $count + ($library['indexedUnavailableFiles'] ?? -1) && ($library[$role === 'music' ? 'songs' : 'videos'] ?? null) === $count;
            if ($role === 'music') {
                $metadata = $metadata && ($library['songsWithMbid'] ?? null) === 2000 && ($library['albumsWithMbid'] ?? null) === 100;
            }
        }
        $empty = true;
        foreach (['async', 'scheduler'] as $role) {
            $queue = $queues[$role] ?? [];
            $empty = $empty && ($queue['state'] ?? null) === 'known' && ($queue['unreadCount'] ?? null) === 0
                && ($queue['pendingCount'] ?? null) === 0 && ($queue['dueCount'] ?? null) === 0;
        }
        $failed = $retried = $maxAttempts = $pending = 0;
        foreach ($snapshot['jobs']['groups'] ?? [] as $group) {
            $count = (int) ($group['count'] ?? 0);
            $failed += in_array($group['status'] ?? null, ['failed', 'cancelled'], true) ? $count : 0;
            $pending += in_array($group['status'] ?? null, ['queued', 'running'], true) ? $count : 0;
            $retried += (int) ($group['retried'] ?? 0);
            $maxAttempts = max($maxAttempts, (int) ($group['max_attempt'] ?? 0));
        }
        $healthy = $empty && $pending === 0 && $failed === 0 && ($snapshot['outbox']['dead'] ?? null) === 0 && ($snapshot['outbox']['pending'] ?? null) === 0 && $maxAttempts <= 4;
        $base = ['remaining' => $pending, 'failed' => $failed, 'lost' => $healthy ? 0 : null, 'retried' => $retried, 'maxAttempts' => $maxAttempts, 'configuredMaximumAttempts' => 4, 'completedAtSeconds' => $elapsed,
            'basis' => 'Exact catalog counts and music metadata identities, completed scan claims, known-empty Redis queues and terminal monitored jobs/outbox.',
            'movieMetadataEnrichment' => 'unexercised: the normal scan and metadata sync do not call the movie enricher'];
        return ['scan' => ['completed' => $scan && $healthy] + $base, 'metadata' => ['completed' => $scan && $metadata && $healthy] + $base];
    }

    /** @param array<string,mixed> $state
     * @param array{monotonic:float,epochSeconds:float} $marker
     * @return array<string,mixed>
     */
    private function waitMaintenance(array $state, Application $application, Observer $observer, string $directory, array $marker, float $minimumDuration = 0, ?RunningProcess $maintenance = null, bool $requireMetadata = true): array
    {
        $from = gmdate('Y-m-d\TH:i:s', (int) $marker['epochSeconds']) . 'Z';
        $sampler = new DatabaseSampler(array_map(static fn(array $library): string => $library['id'], $state['provision']['libraries']), $from);
        $until = Runtime::now() + $this->configuration['maintenanceDeadlineSeconds'];
        $completed = [];
        while (Runtime::now() < $until) {
            if ($maintenance !== null && !$this->runtime->alive($maintenance) && ($maintenance->result()->exitCode ?? 1) !== 0) {
                throw new \RuntimeException('Normal maintenance command failed during playback.');
            }
            if (!$observer->alive()) {
                throw new \RuntimeException('Host observer exited before workload completion.');
            }
            $roles = $this->roles();
            $clock = $observer->update($roles, 'measured');
            if ($clock['monotonic'] - $marker['monotonic'] > $this->configuration['maintenanceDeadlineSeconds']) {
                throw new \RuntimeException('Maintenance exceeded its declared target-host deadline.');
            }
            $snapshot = $this->database($sampler);
            $queues = $application->queues();
            $evidence = self::maintenanceEvidence($snapshot, $queues, $clock['monotonic'] - $marker['monotonic']);
            $diagnostics = $this->diagnostics();
            foreach ($evidence as $role => $completion) {
                if ($completion['completed']) {
                    $completed[$role] ??= $completion;
                    if ($maintenance === null || !$this->runtime->alive($maintenance)) {
                        $completed[$role]['completedAtEpochSeconds'] ??= $clock['epochSeconds'];
                    }
                }
            }
            $line = ['monotonic' => $clock['monotonic'], 'epochSeconds' => $clock['epochSeconds'], 'snapshot' => $snapshot, 'queues' => $queues, 'completion' => $evidence, 'diagnostics' => $diagnostics];
            $encoded = json_encode($line, JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($directory . '/database.jsonl', $encoded, FILE_APPEND | LOCK_EX) !== strlen($encoded)) {
                throw new \RuntimeException('Cannot retain the complete database observation.');
            }
            if ($evidence['scan']['completed'] && (!$requireMetadata || $evidence['metadata']['completed']) && $clock['monotonic'] - $marker['monotonic'] >= $minimumDuration) {
                return $completed;
            }
            $this->runtime->pause(5);
        }
        throw new \RuntimeException('Maintenance did not complete within the declared deadline.');
    }

    /** @return array<string,mixed> */
    private function diagnostics(): array
    {
        $deployment = $this->deploymentState();
        $name = $deployment['configuration']['name'];
        $file = $deployment['composeFile'];
        $home = ApplicationLayout::fromArray($deployment['layout']);
        $arguments = ['exec', '-T', 'web', 'php', $home->path('bin/console'), 'app:server:stats', '--json', '--no-interaction'];
        for ($attempt = 1; ; ++$attempt) {
            $web = $this->docker->compose($name, $file, $arguments, timeout: 30, check: false);
            $output = substr($web->stdout . $web->stderr, 0, 2048);
            if ($web->exitCode === 0 || $attempt === 3 || !self::unansweredControlRequest($output)) {
                break;
            }
            // Keep every unanswered request as evidence; only the measurement sample is retried.
            $this->webControlRetries[] = ['epochSeconds' => microtime(true), 'output' => $output];
        }
        if ($web->exitCode !== 0) {
            throw new \RuntimeException('Web server statistics failed: ' . trim($output));
        }
        $statistics = json_decode(trim($web->stdout), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($statistics) || ($statistics['missing_workers'] ?? null) !== [] || ($statistics['worker_errors'] ?? null) !== []) {
            throw new \RuntimeException('Web worker or pool diagnostics are incomplete.');
        }
        $redis = $this->docker->compose($name, $file, ['exec', '-T', 'redis', 'sh', '-ec', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --raw INFO memory persistence stats errorstats'], timeout: 15)->stdout;
        $info = [];
        foreach (explode("\n", $redis) as $line) {
            if (str_contains($line, ':') && !str_starts_with($line, '#')) {
                [$key, $value] = explode(':', trim($line), 2);
                $info[$key] = $value;
            }
        }
        if (($info['maxmemory_policy'] ?? null) !== 'noeviction' || (int) ($info['evicted_keys'] ?? 0) !== 0
            || isset($info['errorstat_OOM']) || ($info['aof_last_write_status'] ?? null) !== 'ok') {
            $this->unexpectedExits[] = 'Redis eviction, persistence or required-write failure observed.';
        }
        return ['web' => $statistics, 'redis' => $info];
    }

    /** @return array<string,mixed> */
    private function database(DatabaseSampler $sampler): array
    {
        $deployment = $this->deploymentState();
        $environment = $deployment['configuration']['environment'];
        $output = $this->docker->compose($deployment['configuration']['name'], $deployment['composeFile'], ['exec', '-T', 'postgres', 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', $environment['DB_USERNAME'], '-d', $environment['DB_DATABASE']], input: $sampler->sql(), timeout: 15)->stdout;
        return $sampler->parse($output);
    }

    /** @return array<string,int> */
    private function roles(): array
    {
        $deployment = $this->deploymentState();
        $ids = [];
        foreach (['org.baander.household.owner=' . $deployment['owner'], 'app.baander.worker.namespace=' . $this->configuration['name']] as $label) {
            $output = trim($this->docker->run(['ps', '--all', '--quiet', '--no-trunc', '--filter', 'label=' . $label])->stdout);
            $ids = [...$ids, ...(preg_split('/\s+/', $output, flags: PREG_SPLIT_NO_EMPTY) ?: [])];
        }
        if ($ids === []) {
            return [];
        }
        $roles = Observer::rolesFromInspection(json_decode($this->docker->run(['inspect', ...array_values(array_unique($ids))])->stdout, true, flags: JSON_THROW_ON_ERROR), $this->configuration['name'], $deployment['owner']);
        foreach ($this->observedRoles as $role => $pid) {
            if (($roles[$role] ?? null) !== $pid) {
                $this->unexpectedExits[] = 'Required role exited or changed PID: ' . $role;
            }
        }
        $this->observedRoles = $roles;
        return $roles;
    }

    /** @param array<string,mixed> $state */
    private function observer(array $state, string $directory): Observer
    {
        return new Observer($this->docker, $this->runtime, $directory, $state['images']['app'], ApplicationLayout::fromArray($this->deploymentState()['layout']), $this->configuration['observerMemoryMiB']);
    }

    private function household(): Household
    {
        return new Household(new HouseholdStateStore($this->directory . '/deployment'), $this->docker, $this->runtime->executor, $this->runtime->environment, $this->runtime->root, $this->runtime->configuration->budgets->seconds('cleanup'));
    }

    /** @return array<string,mixed> */
    private function deploymentState(): array
    {
        return new HouseholdStateStore($this->directory . '/deployment')->load() ?? throw new \RuntimeException('Household deployment state is missing.');
    }

    /** @return array<string,mixed> */
    private function dataset(): array
    {
        return AtomicFile::readJson($this->directory . '/dataset-manifest.json');
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $state = AtomicFile::readJson($this->statePath());
        if (($state['version'] ?? null) !== 1 || ($state['target'] ?? null) != $this->docker->target->toArray()) {
            throw new \RuntimeException('Qualification state belongs to another Docker target.');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function save(array $state): void
    {
        AtomicFile::writeJson($this->statePath(), $state);
    }

    private function statePath(): string
    {
        return $this->directory . '/qualification.json';
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function publicState(array $state): array
    {
        return ['version' => 1, 'name' => $this->configuration['name'], 'phase' => $state['phase'], 'profile' => $this->configuration['deployment']['profile'],
            'stateDirectory' => $this->directory, 'datasetDigest' => $state['datasetDigest'] ?? null, 'runs' => $state['runs'], 'qualificationClaim' => false];
    }
}
