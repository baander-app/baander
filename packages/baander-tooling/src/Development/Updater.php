<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Build\SourceUnavailable;

class Updater
{
    private const array INTERRUPTED_PHASES = ['database_mutating', 'quiescing', 'checkpointing', 'activating', 'blocked'];
    public ?Builder $builder = null;
    public Runtime $runtime;
    public Gateway $gateway;
    public Database $database;
    public Bootstrap $bootstrap;
    public Workers $workers;
    public Libraries $libraries;

    /** @var array<string,string> */
    private readonly array $environment;
    private readonly Connections $connections;
    private readonly ?float $drainSeconds;

    /** @param array<string,string>|null $environment */
    public function __construct(private readonly StateStore $store, private readonly CommandExecutor $runner, private readonly ?ToolingConfiguration $configuration = null, ?array $environment = null, ?float $drainSeconds = null)
    {
        $this->environment = $environment ?? getenv();
        $this->drainSeconds = $drainSeconds ?? $configuration?->budgets->seconds('process');
        $connections = $this->connections = new Connections($runner, Environment::clean($this->environment));
        $this->runtime = new Runtime($store, $runner, $connections);
        $this->gateway = new Gateway($store, $runner, $connections);
        $this->database = new Database($store, $runner, $connections);
        $this->bootstrap = new Bootstrap($store, $runner, $connections);
        $this->workers = new Workers($store, $runner, $connections);
        $this->libraries = new Libraries($runner, $connections);
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    private function prepare(array &$instance): array
    {
        if ($this->builder === null) {
            $configuration = $this->configuration;
            if ($configuration === null) {
                if (!isset($instance['tooling'])) {
                    throw new WorkflowError('Capture new preparation settings with up before building a release.');
                }
                $configuration = ToolingConfiguration::fromArray($instance['tooling']);
            }
            $instance['tooling'] = $configuration->toArray();
            $this->builder = new Builder($this->store, $this->runner, $configuration, $this->connections);
        }
        return $this->builder->prepare($instance);
    }

    /** @param array<string,mixed> $instance */
    public function head(array $instance): string
    {
        $result = $this->runner->run(new ProcessSpec(['git', 'ls-remote', '--exit-code', '--', $instance['repository'], 'refs/heads/master'], timeout: 60, processGroup: true));
        $fields = preg_split('/\s+/', trim($result->stdout)) ?: [];
        if (count($fields) !== 2 || $fields[1] !== 'refs/heads/master') {
            throw new WorkflowError('Remote master could not be resolved.');
        }
        return $fields[0];
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $values
     */
    public function save(array &$instance, array $values): void
    {
        $instance = array_replace($instance, $values);
        $this->store->save($instance['id'], $instance);
    }

    public function update(string $identity, bool $automatic = false, bool $retry = false): void
    {
        $observed = $this->store->load($identity);
        if (!empty($observed['media'])) {
            $savedLayout = $observed['release']['layout'] ?? $observed['prepared_release']['layout'] ?? null;
            $layout = $savedLayout === null
                ? \Baander\Tooling\Configuration\ApplicationLayout::load()
                : \Baander\Tooling\Configuration\ApplicationLayout::fromArray($savedLayout);
            new Mounts($layout)->preflight(
                $observed['media'],
                $this->store->root,
                $this->runtime->environment($observed),
                array_column(array_diff_key($observed['state_mounts'] ?? [], ['media' => true]), 'target'),
            );
        }
        new RetainedState($this->store, $this->runner, $this->environment, $this->drainSeconds)->convert($identity);
        $lock = $this->store->lock($identity);
        try {
            $instance = $this->store->load($identity);
            if ($instance === null) {
                throw new WorkflowError('Configure this development instance first.');
            }
            if ($instance['kind'] === 'persistent' && !$this->store->enabled($identity)) {
                if ($automatic) {
                    return;
                }
                throw new WorkflowError('The persistent instance is disabled; resume it with make dev-up.');
            }
            if (in_array($instance['phase'] ?? null, self::INTERRUPTED_PHASES, true)) {
                $this->save($instance, [
                    'phase' => 'blocked',
                    'failure' => 'An interrupted activation requires explicit recovery; no release was started.',
                ]);
                throw new WorkflowError($instance['failure']);
            }
            if ($automatic && microtime(true) < ($instance['next_check_at'] ?? 0)) {
                return;
            }
            try {
                $detected = $instance['kind'] === 'persistent' ? $this->head($instance) : null;
            } catch (WorkflowError|\Baander\Tooling\Process\CommandFailure $error) {
                $delay = min(300, max(60, ($instance['retry_delay'] ?? 30) * 2));
                $this->save($instance, [
                    'retry_delay' => $delay,
                    'next_check_at' => microtime(true) + $delay,
                    'failure' => 'Remote unavailable; the running release is unchanged.',
                    'failure_stage' => 'fetch',
                ]);
                throw $error;
            }
            $observation = ['detected_revision' => $detected];
            if (($instance['failure_stage'] ?? null) === 'fetch') {
                $observation += ['failure' => null, 'failure_stage' => null];
            }
            foreach ($observation as $key => $value) {
                if (!array_key_exists($key, $instance) || $instance[$key] !== $value) {
                    $this->save($instance, $observation);
                    break;
                }
            }
            if ($detected && !$retry && ($instance['failed_revision'] ?? null) === $detected) {
                return;
            }
            $retainedRelease = null;
            if (($instance['phase'] ?? null) === 'ready' && !empty($instance['running_revision'])) {
                if ($this->runtime->running($instance) && $this->workers->running($instance)) {
                    if ($detected === $instance['running_revision'] && !$retry) {
                        return;
                    }
                } else {
                    $this->save($instance, ['readiness' => null]);
                    if ($detected === $instance['running_revision'] && !$retry) {
                        $retainedRelease = $instance['release'] ?? null;
                    }
                }
            }
            $previousPhase = $instance['phase'] ?? null;
            $wasReady = $previousPhase === 'ready' && !empty($instance['running_revision']) && !empty($instance['readiness']);
            $this->save($instance, [
                'phase' => 'preparing',
                'target_revision' => $detected,
                'failure' => null,
                'database_mutation_started' => false,
                'database_mutation_confirmed' => false,
            ]);
            try {
                $release = $retainedRelease ?? $this->prepare($instance);
            } catch (SourceUnavailable $error) {
                $instance = $this->required($identity);
                $delay = min(300, max(60, ($instance['retry_delay'] ?? 30) * 2));
                $this->save($instance, [
                    'phase' => $previousPhase,
                    'retry_delay' => $delay,
                    'next_check_at' => microtime(true) + $delay,
                    'failure' => 'Source download unavailable; the previous runtime is unchanged.',
                    'failure_stage' => 'fetch',
                ]);
                throw $error;
            } catch (\Throwable $error) {
                $instance = $this->required($identity);
                $this->save($instance, [
                    'phase' => 'preparation_failed',
                    'failed_revision' => $instance['target_revision'] ?? $detected,
                    'failure' => 'Release preparation failed; the previous runtime is unchanged. Inspect private build logs.',
                ]);
                throw $error;
            }
            $this->save($instance, [
                'target_revision' => $release['revision'],
                'prepared_release' => $release,
                'retry_delay' => 0,
                'next_check_at' => 0,
                'failure_stage' => null,
            ]);
            if ($instance['kind'] === 'persistent' && !$this->store->enabled($identity)) {
                $this->save($instance, ['phase' => $wasReady ? 'ready' : 'stopped']);
                return;
            }
            try {
                $this->activate($instance, $release);
            } catch (\Throwable $error) {
                $current = $this->required($identity);
                if (($current['phase'] ?? null) === 'preflight') {
                    $this->save($current, [
                        'phase' => $wasReady ? 'ready' : ($previousPhase === 'stopped' ? 'stopped' : 'preparation_failed'),
                        'failed_revision' => $retainedRelease !== null ? null : $release['revision'],
                        'failure' => 'Release preflight failed; existing data and runtime were not changed.',
                    ]);
                } elseif (($current['phase'] ?? null) === 'checkpointing' && !empty($current['release'])) {
                    try {
                        $current = array_replace($current, $this->runtime->write($current, $current['release']));
                        $this->startRelease($current, $current['release']);
                        $this->save($current, [
                            'failed_revision' => $release['revision'],
                            'failure' => 'Checkpoint or rehearsal failed; the previous release was restarted without database changes.',
                        ]);
                    } catch (\Throwable) {
                        $this->save($current, [
                            'phase' => 'blocked',
                            'failure' => 'Restoring service before migration was not confirmed; inspect the saved attempt.',
                        ]);
                    }
                } elseif (!in_array($current['phase'] ?? null, ['blocked', 'ready'], true)) {
                    $this->save($current, ['phase' => 'blocked', 'failure' => 'Activation was not confirmed. Inspect the saved attempt before recovery.']);
                }
                throw $error;
            }
            if ($instance['kind'] === 'persistent') {
                $current = $this->required($identity);
                try {
                    $this->save($current, ['detected_revision' => $this->head($instance)]);
                } catch (WorkflowError|\Baander\Tooling\Process\CommandFailure $error) {
                    if ($error instanceof \Baander\Tooling\Process\CommandFailure && ($error->getCode() === 124 || $error->result?->cancelled || $error->result?->signal !== null)) {
                        throw $error;
                    }
                    $this->save($current, ['failure' => 'Release is ready; checking for a newer master will retry on the next tick.']);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function required(string $identity): array
    {
        return $this->store->load($identity) ?? throw new WorkflowError('Development instance state disappeared; retain recovery artifacts.');
    }

    /** @param array<string,mixed> $instance */
    public function freeze(array &$instance, bool $route = true): void
    {
        $this->save($instance, [
            'phase' => 'quiescing',
            'writers_stopped' => false,
            'readiness' => null,
            'last_ready_revision' => $instance['running_revision'] ?? $instance['last_ready_revision'] ?? null,
            'running_revision' => null,
        ]);
        if ($route) {
            $this->gateway->route($instance, maintenance: true);
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $docker->compose($instance['project'], $instance['compose_file'], ['stop', '--timeout', '35', 'app', ...($instance['kind'] === 'preview' ? ['vite'] : [])], timeout: 90);
        $this->workers->retire($instance);
        $this->save($instance, ['writers_stopped' => true]);
    }

    /** @param array<string,mixed> $instance */
    public function freezeAdoption(array &$instance): void
    {
        $this->save($instance, [
            'phase' => 'quiescing',
            'writers_stopped' => false,
            'readiness' => null,
            'last_ready_revision' => $instance['running_revision'] ?? $instance['last_ready_revision'] ?? null,
            'running_revision' => null,
        ]);
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        foreach (['nginx', 'app'] as $role) {
            if (isset($instance['adoption']['containers'][$role])) {
                $docker->run(['stop', '--time', '35', $instance['adoption']['containers'][$role]], timeout: 90);
            }
        }
        foreach ($instance['adoption']['worker_manifests'] as $record) {
            foreach (['directory', 'operator_image', 'socket', 'network'] as $key) {
                if (!array_key_exists($key, $record)) {
                    throw new WorkflowError('Adoption requires each original worker controller record and its network.');
                }
            }
            $original = array_replace($instance, ['workers' => $record, 'network' => $record['network']]);
            $this->workers->retire($original);
        }
        $observation = $this->database->sql($instance, "BEGIN READ ONLY; SELECT json_build_object('remaining', "
            . "(SELECT count(*) FROM worker_deployment_boots WHERE retired_at IS NULL) + "
            . "(SELECT count(*) FROM worker_deployment_leases WHERE state='active')); COMMIT;");
        if (($observation['remaining'] ?? null) !== 0) {
            throw new WorkflowError('Unaccounted worker ownership prevents adoption; retain the original controller records.');
        }
        $this->save($instance, ['writers_stopped' => true]);
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function activate(array &$instance, array $release): void
    {
        $contract = ReleaseContract::fromArray($release);
        $contract->validateInputs($instance);
        $instance['media'] = $release['effective']['media'];
        $instance['libraries'] = $release['effective']['libraries'];
        foreach (['state_mounts', 'volumes', 'postgres_image', 'redis_image'] as $field) {
            $instance[$field] = $release['effective'][$field];
        }
        $previous = $instance['release'] ?? null;
        $adopting = !empty($instance['adoption']) && empty($instance['ownership_transferred']);
        foreach (['postgres_image', 'redis_image'] as $key) {
            if (!empty($instance[$key])) {
                $release['images'][$key]['resolvedId'] = $instance[$key];
            } elseif (!empty($previous['images'][$key]['resolvedId'])) {
                $release['images'][$key]['resolvedId'] = $previous['images'][$key]['resolvedId'];
            }
        }
        $this->save($instance, ['prepared_release' => $release, 'phase' => 'preflight']);
        if ($adopting) {
            $instance = array_replace($instance, $this->bootstrap->initialize($instance, $release));
            $catalog = $this->database->inspect($instance, $release);
            $this->freezeAdoption($instance);
        } elseif ($previous) {
            $instance = array_replace($instance, $this->runtime->write($instance, $previous));
            $this->runtime->dependencies($instance);
            $this->gateway->start();
            $catalog = $this->database->inspect($instance, $release);
            $this->freeze($instance);
        } else {
            $instance = array_replace($instance, $this->runtime->write($instance, $release));
            $this->gateway->start();
            $this->gateway->route($instance, maintenance: true);
            $this->runtime->dependencies($instance);
            $this->save($instance, ['writers_stopped' => true]);
            $catalog = $this->database->inspect($instance, $release);
        }
        $checkpoint = null;
        if (!$catalog['fresh'] && ($catalog['pending'] || $adopting)) {
            $this->save($instance, ['phase' => 'checkpointing']);
            $checkpoint = $this->database->checkpoint($instance, $release);
            $this->save($instance, ['checkpoint' => $checkpoint]);
            $checkpoint = $this->database->rehearse($instance, $release, $checkpoint);
            $this->save($instance, ['checkpoint' => $checkpoint]);
        }
        if ($adopting) {
            $this->save($instance, ['phase' => 'quiescing']);
            $instance = array_replace($instance, $this->bootstrap->transfer($instance, $release, $checkpoint));
            foreach (['state_mounts', 'volumes', 'postgres_image', 'redis_image'] as $field) {
                $release['effective'][$field] = $instance[$field] ?? $release['effective'][$field];
            }
            $this->save($instance, ['prepared_release' => $release]);
        }
        $instance = array_replace($instance, $this->runtime->write($instance, $release));
        $this->runtime->dependencies($instance);
        if ($adopting) {
            $this->gateway->start();
            $this->gateway->route($instance, maintenance: true);
        }
        if (!in_array($instance['initialization'] ?? null, ['complete', 'adopted'], true)) {
            $instance = array_replace($instance, $this->bootstrap->initialize($instance, $release));
        } elseif ($catalog['pending']) {
            $this->runtime->prepare($instance, $release);
            $instance = array_replace($instance, $this->database->migrate($instance, $release, $checkpoint));
        }
        $this->startRelease($instance, $release);
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function startRelease(array &$instance, array $release): void
    {
        $this->save($instance, ['phase' => 'activating', 'writers_stopped' => false, 'readiness' => null]);
        $this->runtime->dependencies($instance);
        $this->runtime->up($instance, $release);
        $this->workers->prepare($instance, $release);
        $this->workers->start($instance);
        $this->workers->ready($instance);
        $this->gateway->route($instance, maintenance: false);
        $deadline = hrtime(true) + 90_000_000_000;
        while (true) {
            try {
                $readiness = $this->runtime->ready($instance);
                break;
            } catch (WorkflowError|\Baander\Tooling\Process\CommandFailure $error) {
                if ($error instanceof \Baander\Tooling\Process\CommandFailure && ($error->getCode() === 124 || $error->result?->cancelled || $error->result?->signal !== null)) {
                    throw $error;
                }
                if (hrtime(true) >= $deadline) {
                    $this->gateway->route($instance, maintenance: true);
                    throw $error;
                }
                usleep(2_000_000);
            }
        }
        $this->libraries->ensure($instance);
        $this->save($instance, [
            'phase' => 'ready',
            'release' => $release,
            'running_revision' => $release['revision'],
            'worker_snapshot' => $release['revision'],
            'readiness' => array_replace($readiness, ['workers_ready' => true]),
            'postgres_image' => $release['images']['postgres_image']['resolvedId'],
            'redis_image' => $release['images']['redis_image']['resolvedId'] ?? null,
            'failed_revision' => null,
            'failure' => null,
            'database_mutation_started' => false,
            'database_mutation_confirmed' => false,
        ]);
    }

    public function down(string $identity): void
    {
        new RetainedState($this->store, $this->runner, $this->environment, $this->drainSeconds)->convert($identity, activation: false);
        $this->store->setEnabled($identity, false);
        $lock = $this->store->lock($identity);
        try {
            $instance = $this->store->load($identity);
            if ($instance === null || empty($instance['compose_file'])) {
                return;
            }
            ReleaseContract::validateGeneratedInputs($instance);
            $blocked = in_array($instance['phase'] ?? null, self::INTERRUPTED_PHASES, true);
            $this->freeze($instance, route: is_file($this->store->root . '/gateway/state.json'));
            $this->runtime->down($instance);
            $this->gateway->remove($instance);
            $this->save($instance, ['phase' => $blocked ? 'blocked' : 'stopped', 'readiness' => null]);
        } finally {
            $lock->release();
        }
    }

    public function recover(string $identity): void
    {
        new RetainedState($this->store, $this->runner, $this->environment, $this->drainSeconds)->convert($identity);
        $lock = $this->store->lock($identity);
        $recovering = false;
        try {
            $instance = $this->store->load($identity);
            if ($instance === null || ($instance['phase'] ?? null) !== 'blocked' || empty($instance['prepared_release'])) {
                throw new WorkflowError('Recovery requires a blocked attempt with retained release artifacts.');
            }
            $recovering = true;
            $release = $instance['prepared_release'];
            ReleaseContract::fromArray($release)->validateInputs($instance);
            $instance['media'] = $release['effective']['media'];
            $instance['libraries'] = $release['effective']['libraries'];
            foreach (['state_mounts', 'volumes', 'postgres_image', 'redis_image'] as $field) {
                $instance[$field] = $release['effective'][$field];
            }
            $adopting = !empty($instance['adoption']) && empty($instance['ownership_transferred']);
            if ($adopting) {
                $docker = $this->connections->client($instance['docker']);
                $docker->validateIdentity();
                foreach ($instance['adoption_inventory'] as $saved) {
                    $current = json_decode($docker->run(['inspect', $saved['Id']])->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
                    foreach (['Id', 'Image', 'Mounts'] as $key) {
                        if (($current[$key] ?? null) != ($saved[$key] ?? null)) {
                            throw new WorkflowError('Original adoption ownership changed; reconcile it before recovery.');
                        }
                    }
                }
                foreach (['postgres', 'redis'] as $role) {
                    $docker->run(['start', $instance['adoption_inventory'][$role]['Id']]);
                }
                $docker->run([
                    'exec',
                    $instance['adoption_inventory']['postgres']['Id'],
                    '/bin/sh',
                    '-ec',
                    'for n in $(seq 1 60); do pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" && exit 0; sleep 1; done; exit 1',
                ], timeout: 70);
            } else {
                $instance = array_replace($instance, $this->runtime->write($instance, $release));
                $this->runtime->dependencies($instance);
                $this->gateway->start();
                $this->gateway->route($instance, maintenance: true);
            }
            $catalog = $this->database->inspect($instance, $release);
            if ($catalog['pending'] && !empty($instance['database_mutation_started'])) {
                throw new WorkflowError('Database reconciliation is still required. Preserve the checkpoint; no migration or reset was attempted.');
            }
            if ($adopting || empty($instance['database_mutation_started'])) {
                $this->activate($instance, $release);
                return;
            }
            $this->save($instance, ['migration_hashes' => $catalog['migration_hashes']]);
            $this->freeze($instance);
            if (($instance['initialization'] ?? null) === 'initializing') {
                $instance = array_replace($instance, $this->bootstrap->initialize($instance, $release));
            }
            $this->startRelease($instance, $release);
        } catch (\Throwable $error) {
            if ($recovering) {
                try {
                    $current = $this->required($identity);
                    $this->save($current, ['phase' => 'blocked', 'failure' => 'Recovery was not confirmed; retain the saved attempt and checkpoint.']);
                } catch (\Throwable) {
                }
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }
}
