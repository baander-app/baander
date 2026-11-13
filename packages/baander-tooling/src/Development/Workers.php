<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\{ImageProfile, WorkerConfiguration, WorkerLifecycle};
use Baander\Tooling\Process\CommandExecutor;

class Workers
{
    private readonly Connections $connections;

    public function __construct(private readonly StateStore $store, private readonly CommandExecutor $runner, ?Connections $connections = null)
    {
        $this->connections = $connections ?? new Connections($runner, Environment::clean());
    }

    /** @param array<string,mixed> $instance */
    protected function lifecycle(array $instance): WorkerLifecycle
    {
        return new WorkerLifecycle(new WorkerStateStore($this->store, $instance), $this->runner, $this->connections->environment());
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function prepare(array &$instance, array $release): void
    {
        $this->guard(function () use (&$instance, $release): void {
            $layout = ApplicationLayout::fromArray($release['layout']);
            $home = $layout->root;
            $mounts = [];
            foreach ($instance['media'] ?? [] as $item) {
                $mounts[] = [
                    'source' => \Baander\Tooling\Filesystem\Paths::resolvedPath($item['source']),
                    'target' => $item['target'],
                    'role' => 'source',
                    'readOnly' => true,
                ];
            }
            $runtime = new Runtime($this->store, $this->runner, $this->connections);
            $environment = $runtime->environment($instance);
            $environment = array_replace($environment, Runtime::databaseUrls($environment, $release));
            $configuration = $runtime->config($instance, $release);
            foreach ([
                ['media', $environment['MEDIA_STORAGE_PATH'] ?? '/storage/media', 'source'],
                ['storage', $home . '/storage', 'derived'],
                ['conversions', $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions', 'derived'],
                ['transcode_state', $home . '/var/transcode_state', 'derived'],
            ] as [$key, $defaultTarget, $role]) {
                $target = $instance['state_mounts'][$key]['target'] ?? $defaultTarget;
                if ($role === 'source' && array_any(
                    $mounts,
                    static fn(array $mount): bool => $mount['target'] === $target,
                )) {
                    continue;
                }
                $configured = array_find(
                    $configuration['services']['app']['volumes'],
                    static fn(array $mount): bool => $mount['target'] === $target,
                );
                if ($configured && $role === 'source' && $configured['type'] === 'bind' && !empty($configured['read_only'])) {
                    $mounts[] = [
                        'source' => \Baander\Tooling\Filesystem\Paths::resolvedPath($configured['source']),
                        'target' => $target,
                        'role' => 'source',
                        'readOnly' => true,
                    ];
                    continue;
                }
                if ($configured === null || $configured['type'] !== 'volume') {
                    throw new WorkflowError('Worker shared ' . $key . ' requires a named volume; relocate adopted bind storage before enabling workers.');
                }
                $name = $configuration['volumes'][$configured['source']]['name'];
                $mounts[] = [
                    'volume' => $name,
                    'target' => $target,
                    'role' => $role,
                    'readOnly' => $role === 'source',
                ];
            }
            $allowed = [
                'APP_SECRET',
                'DATABASE_URL',
                'DATABASE_CONTROL_URL',
                'REDIS_URL',
                'REDIS_PASSWORD',
                'MESSENGER_TRANSPORT_DSN',
                'MAILER_DSN',
                'CONVERT_STORAGE_PATH',
                'MEDIA_STORAGE_PATH',
                'MAIL_FROM_ADDRESS',
                'MAIL_FROM_NAME',
                'VAPID_PRIVATE_KEY',
                'VAPID_PUBLIC_KEY',
                'STREAM_URL_HMAC_SECRET',
            ];
            $environment = array_replace(array_intersect_key($environment, array_flip($allowed)), [
                'APP_ENV' => 'prod', 'APP_DEBUG' => '0',
                'APP_URL' => 'https://' . $instance['hostname'],
                'DEFAULT_URI' => 'https://' . $instance['hostname'],
            ]);
            $cleanupSeconds = $release['effective']['budgets']['cleanup'] ?? 120.0;
            $profile = ImageProfile::resolve(
                $this->connections->client($instance['docker']),
                $release['images']['worker_image']['resolvedId'],
                $layout,
                ImageProfile::DEFAULT_SETTINGS,
                $this->store->directory($instance['id']) . '/worker-profile-resources.json',
                $this->connections->environment(),
                $cleanupSeconds,
            );
            $configuration = new WorkerConfiguration(
                namespace: $instance['project'],
                target: $instance['docker'],
                network: Runtime::networkName($instance),
                workerImage: $release['images']['worker_image']['resolvedId'],
                operatorImage: $release['images']['operator_image']['resolvedId'],
                layout: $layout,
                runtimeEnvironment: $environment,
                sourceMountPolicy: 'local-development',
                mediaMounts: $mounts,
                resourceLabels: ['app.baander.development.owner' => $instance['id']],
                profile: $profile,
                cleanupSeconds: $cleanupSeconds,
            );
            $record = WorkerStateStore::hydrate($instance);
            try {
                $this->lifecycle($instance)->prepare($record, $configuration);
            } finally {
                if ($record !== null) {
                    $instance['workers'] = $record;
                }
            }
        });
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function invoke(array $instance, string $action): array
    {
        return $this->guard(fn(): array => $this->lifecycle($instance)->invoke(WorkerStateStore::hydrate($instance), $action));
    }

    /** @param array<string,mixed> $instance */
    public function running(array $instance): bool
    {
        return $this->guard(fn(): bool => $this->lifecycle($instance)->running(WorkerStateStore::hydrate($instance)));
    }

    /** @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function sourceMountArguments(array $manifest): array
    {
        return self::guard(fn(): array => WorkerLifecycle::sourceMountArguments($manifest));
    }

    /** @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function retirementMountArguments(array $manifest, string $applicationRoot): array
    {
        return self::guard(fn(): array => WorkerLifecycle::retirementMountArguments($manifest, $applicationRoot));
    }

    /** @param array<string,mixed> $instance */
    public function phase(array &$instance, string $phase): void
    {
        $this->mutate($instance, fn(WorkerLifecycle $lifecycle, array &$record) => $lifecycle->phase($record, $phase));
    }

    /** @param array<string,mixed> $instance */
    public function start(array &$instance): void
    {
        $this->mutate($instance, fn(WorkerLifecycle $lifecycle, array &$record) => $lifecycle->start($record));
    }

    /** @param array<string,mixed> $instance */
    public function ready(array &$instance, float $timeout = 90): void
    {
        $this->mutate($instance, fn(WorkerLifecycle $lifecycle, array &$record) => $lifecycle->ready($record, $timeout));
    }

    /**
     * A boot whose operator image left the daemon is retired without the operator once none of
     * its containers exists; every other boot retires through its operator.
     *
     * @param array<string,mixed> $instance
     */
    public function retire(array &$instance): void
    {
        if (empty($instance['workers'])) {
            return;
        }
        $this->mutate($instance, static fn(WorkerLifecycle $lifecycle, array &$record) => $lifecycle->operatorLost($record)
            ? $lifecycle->retireWithoutOperator($record)
            : $lifecycle->retire($record));
    }

    /**
     * Fails before anything is changed when the recorded boot's operator image left the daemon
     * while containers of the boot still exist.
     *
     * @param array<string,mixed> $instance
     */
    public function assertRetirable(array $instance): void
    {
        $this->guard(fn() => $this->lifecycle($instance)->assertRetirable(WorkerStateStore::hydrate($instance)));
    }

    /**
     * Whether the recorded boot has to retire, or already retired, without its operator. Such a
     * boot can still be registered in the database, which only the operator retires.
     *
     * @param array<string,mixed> $instance
     */
    public function operatorLost(array $instance): bool
    {
        return !empty($instance['workers']['operator_lost'])
            || $this->guard(fn(): bool => $this->lifecycle($instance)->operatorLost(WorkerStateStore::hydrate($instance)));
    }

    /** @param array<string,mixed> $instance
     * @param callable(WorkerLifecycle,array<string,mixed>&):void $operation
     */
    private function mutate(array &$instance, callable $operation): void
    {
        $this->guard(function () use (&$instance, $operation): void {
            $record = WorkerStateStore::hydrate($instance);
            try {
                $operation($this->lifecycle($instance), $record);
            } finally {
                $instance['workers'] = $record;
            }
        });
    }

    /** @template T
     * @param callable():T $operation
     * @return T
     */
    private static function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (WorkflowError|\Baander\Tooling\Process\CommandFailure $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new WorkflowError($error->getMessage(), previous: $error->getPrevious() ?? $error);
        }
    }
}
