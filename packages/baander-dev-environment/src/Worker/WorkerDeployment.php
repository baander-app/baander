<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Worker;

use Baander\Core\EnvironmentVariable;
use Baander\Configuration\ApplicationLayout;
use Baander\Deployment\Profiles\ImageProfile;
use Baander\Deployment\Workers\Configuration;
use Baander\Deployment\Workers\Lifecycle;
use Baander\Process\Execution\CommandExecutor;
use Baander\DevEnvironment\Host\CapturedConnections;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\Host\ProcessEnvironment;
use Baander\DevEnvironment\Runtime\ComposeRuntime;
use Baander\DevEnvironment\WorkflowError;

class WorkerDeployment
{
    private readonly CapturedConnections $connections;

    public function __construct(private readonly InstanceStateStore $store, private readonly CommandExecutor $runner, ?CapturedConnections $connections = null)
    {
        $this->connections = $connections ?? new CapturedConnections($runner, ProcessEnvironment::clean());
    }

    /** @param array<string,mixed> $instance */
    protected function lifecycle(array $instance): Lifecycle
    {
        return new Lifecycle(new InstanceWorkerStateStore($this->store, $instance), $this->runner, $this->connections->environment());
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
                    'source' => \Baander\Filesystem\Paths::resolvedPath($item['source']),
                    'target' => $item['target'],
                    'role' => 'source',
                    'readOnly' => true,
                ];
            }
            $runtime = new ComposeRuntime($this->store, $this->runner, $this->connections);
            $environment = $runtime->environment($instance);
            $environment = array_replace($environment, ComposeRuntime::databaseUrls($environment, $release));
            $configuration = $runtime->config($instance, $release);
            foreach ([
                ['media', $environment[EnvironmentVariable::MEDIA_STORAGE_PATH] ?? '/storage/media', 'source'],
                ['storage', $home . '/storage', 'derived'],
                ['conversions', $environment[EnvironmentVariable::CONVERT_STORAGE_PATH] ?? '/storage/conversions', 'derived'],
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
                        'source' => \Baander\Filesystem\Paths::resolvedPath($configured['source']),
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
                EnvironmentVariable::APP_SECRET,
                EnvironmentVariable::DATABASE_URL,
                EnvironmentVariable::DATABASE_CONTROL_URL,
                EnvironmentVariable::REDIS_URL,
                EnvironmentVariable::REDIS_PASSWORD,
                EnvironmentVariable::MESSENGER_TRANSPORT_DSN,
                EnvironmentVariable::MAILER_DSN,
                EnvironmentVariable::CONVERT_STORAGE_PATH,
                EnvironmentVariable::MEDIA_STORAGE_PATH,
                EnvironmentVariable::MAIL_FROM_ADDRESS,
                EnvironmentVariable::VIDEO_ENCODER,
                EnvironmentVariable::TMDB_BEARER_TOKEN,
                EnvironmentVariable::VAPID_PRIVATE_KEY,
                EnvironmentVariable::VAPID_PUBLIC_KEY,
                EnvironmentVariable::STREAM_URL_HMAC_SECRET,
                EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY,
            ];
            $environment = array_replace(array_intersect_key($environment, array_flip($allowed)), [
                EnvironmentVariable::APP_ENV => 'prod', EnvironmentVariable::APP_DEBUG => '0',
                EnvironmentVariable::APP_URL => 'https://' . $instance['hostname'],
                EnvironmentVariable::DEFAULT_URI => 'https://' . $instance['hostname'],
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
            $logVolume = $configuration['volumes']['logs']['name'];
            $configuration = new Configuration(
                namespace: $instance['project'],
                target: $instance['docker'],
                network: ComposeRuntime::networkName($instance),
                workerImage: $release['images']['worker_image']['resolvedId'],
                operatorImage: $release['images']['operator_image']['resolvedId'],
                layout: $layout,
                runtimeEnvironment: $environment,
                sourceMountPolicy: 'local-development',
                mediaMounts: $mounts,
                resourceLabels: ['app.baander.development.owner' => $instance['id']],
                profile: $profile,
                cleanupSeconds: $cleanupSeconds,
                logVolume: $logVolume,
            );
            $record = InstanceWorkerStateStore::hydrate($instance);
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
        return $this->guard(fn(): array => $this->lifecycle($instance)->invoke(InstanceWorkerStateStore::hydrate($instance), $action));
    }

    /** @param array<string,mixed> $instance */
    public function running(array $instance): bool
    {
        return $this->guard(fn(): bool => $this->lifecycle($instance)->running(InstanceWorkerStateStore::hydrate($instance)));
    }

    /** @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function sourceMountArguments(array $manifest): array
    {
        return self::guard(fn(): array => Lifecycle::sourceMountArguments($manifest));
    }

    /** @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function retirementMountArguments(array $manifest, string $applicationRoot): array
    {
        return self::guard(fn(): array => Lifecycle::retirementMountArguments($manifest, $applicationRoot));
    }

    /** @param array<string,mixed> $instance */
    public function phase(array &$instance, string $phase): void
    {
        $this->mutate($instance, fn(Lifecycle $lifecycle, array &$record) => $lifecycle->phase($record, $phase));
    }

    /** @param array<string,mixed> $instance */
    public function start(array &$instance): void
    {
        $this->mutate($instance, fn(Lifecycle $lifecycle, array &$record) => $lifecycle->start($record));
    }

    /** @param array<string,mixed> $instance */
    public function ready(array &$instance, float $timeout = 90): void
    {
        $this->mutate($instance, fn(Lifecycle $lifecycle, array &$record) => $lifecycle->ready($record, $timeout));
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
        $this->mutate($instance, static fn(Lifecycle $lifecycle, array &$record) => $lifecycle->operatorLost($record)
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
        $this->guard(fn() => $this->lifecycle($instance)->assertRetirable(InstanceWorkerStateStore::hydrate($instance)));
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
            || $this->guard(fn(): bool => $this->lifecycle($instance)->operatorLost(InstanceWorkerStateStore::hydrate($instance)));
    }

    /** @param array<string,mixed> $instance
     * @param callable(Lifecycle,array<string,mixed>&):void $operation
     */
    private function mutate(array &$instance, callable $operation): void
    {
        $this->guard(function () use (&$instance, $operation): void {
            $record = InstanceWorkerStateStore::hydrate($instance);
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
        } catch (WorkflowError|\Baander\Process\Execution\CommandFailure $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new WorkflowError($error->getMessage(), previous: $error->getPrevious() ?? $error);
        }
    }
}
