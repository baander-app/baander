<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Release;

use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Images\Build\BuildRequest;
use Baander\Configuration\ApplicationLayout;
use Baander\Configuration\ToolingConfiguration;
use Baander\Containers\Docker\Client;
use Baander\DevEnvironment\Host\CapturedConnections;
use Baander\Images\Build\Source;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\Host\ProcessEnvironment;
use Baander\DevEnvironment\WorkflowError;
use Baander\Filesystem\PrivateDirectory;

class ReleaseBuilder
{
    private readonly CapturedConnections $connections;

    public function __construct(private readonly ?InstanceStateStore $store, private readonly CommandExecutor $runner, private readonly ToolingConfiguration $configuration, ?CapturedConnections $connections = null)
    {
        $this->connections = $connections ?? new CapturedConnections($runner, ProcessEnvironment::clean());
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function prepare(array &$instance): array
    {
        if ($this->store === null) {
            throw new WorkflowError('Release preparation requires an instance state store.');
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $builds = $this->store->directory($instance['id']) . '/builds';
        PrivateDirectory::prepare($builds);
        $source = $builds . '/' . bin2hex(random_bytes(16));
        if ($instance['kind'] === 'persistent') {
            [, $revision] = Source::checkoutRevision($this->runner, $instance['repository'], $this->store->directory($instance['id']) . '/managed', $source);
        } else {
            $revision = Source::snapshotWorktree($this->runner, $instance['worktree'], $source);
        }
        $layout = ApplicationLayout::load($source . '/packages/baander-configuration/resources/application-layout.json');
        $media = new \Baander\DevEnvironment\Configuration\MediaMounts($layout)->preflight($instance['media'] ?? [], $this->store->root, new \Baander\DevEnvironment\Runtime\ComposeRuntime($this->store, $this->runner)->environment($instance), array_column(array_diff_key($instance['state_mounts'] ?? [], ['media' => true]), 'target'));
        $instance['media'] = $media;
        $instance['target_revision'] = $revision;
        $this->store->save($instance['id'], $instance);
        $release = ['revision' => $revision, 'source' => $source, 'layout' => $layout->toArray()];
        $release['tool_image'] = $this->build($docker, $source, 'dev-tools', $revision);
        $this->frontend($docker, $source, $release['tool_image']);
        $release['app_image'] = $this->build($docker, $source, $instance['kind'] === 'persistent' ? 'production' : 'dev-runtime', $revision);
        $release['worker_image'] = $this->build($docker, $source, 'worker', $revision);
        $release['operator_image'] = $this->build($docker, $source, 'dev-operator', $revision);
        // The database image also runs the pooler. Data keeps its retained PostgreSQL image;
        // the pooler always runs from this release's build. Both roles share one export:
        // every docker build export receives a fresh attestation index digest, so a second
        // build of the shared tag would orphan the identity recorded for the first role.
        $databaseImageId = null;
        foreach (['postgres_image' => 'postgres', 'pooler_image' => 'postgres', 'nginx_image' => 'nginx', 'redis_image' => 'developmentRedis'] as $key => $role) {
            $selection = $this->configuration->images->role($role);
            $retained = in_array($key, ['postgres_image', 'redis_image'], true) ? ($instance[$key] ?? $instance['release']['images'][$key]['resolvedId'] ?? null) : null;
            // A retained image can leave the daemon while its instance is stopped, because no
            // container holds it then; keep it only while the daemon still resolves it and
            // otherwise continue the data on this release's image.
            if ($retained !== null && !$docker->holdsImage($retained)) {
                $retained = null;
            }
            if ($retained !== null) {
                $release[$key] = $retained;
            } elseif ($role === 'postgres' && $this->configuration->provenance['images.postgres.reference'] === 'defaults') {
                if ($databaseImageId === null) {
                    $tag = 'baander-development-postgres:' . $revision . '-' . basename($source);
                    $docker->run(['build', '--file', $source . '/docker/postgres/Dockerfile', '--tag', $tag, $source . '/docker'], timeout: null, logPath: $builds . '/' . basename($source) . '-postgres.log');
                    $databaseImageId = $this->imageId($docker, $tag);
                }
                $release[$key] = $databaseImageId;
            } else {
                $docker->run(['pull', $selection->reference], timeout: 300, logPath: $builds . '/' . basename($source) . '-' . $key . '.log');
                $release[$key] = $this->imageId($docker, $selection->reference);
            }
            $release['image_selections'][$key] = [...$selection->toArray(), 'id' => $release[$key], 'retained' => $retained !== null];
        }
        $release['image_pins'] = [];
        foreach ($release as $key => $image) {
            if (str_ends_with($key, '_image')) {
                $pin = 'baander-development-release-' . substr($key, 0, -6) . ':' . $instance['project'] . '-' . basename($source);
                $docker->run(['image', 'tag', $image, $pin]);
                $release['image_pins'][$key] = $pin;
            }
        }
        return \Baander\DevEnvironment\State\ReleaseContract::prepared($release, $instance, $this->configuration, $this->store)->toArray();
    }

    public function imageId(Client $docker, string $name): string
    {
        return trim($docker->run(['image', 'inspect', '--format', '{{.Id}}', $name])->stdout);
    }

    public function build(Client $docker, string $source, string $target, string $revision): string
    {
        $tag = 'baander-development-' . $target . ':' . $revision . '-' . basename($source);
        $request = BuildRequest::fromSource($source, $target, $revision, $tag, $this->configuration->images->role('operatorHelper'), $this->configuration->images->role('ffmpeg'));
        $docker->run($request->arguments, timeout: null, logPath: dirname($source) . '/' . basename($source) . '-' . $target . '.log');
        return $request->imageId($docker->run(['image', 'inspect', $tag])->stdout);
    }

    public function frontend(Client $docker, string $source, string $image): void
    {
        $name = 'baander-assets-' . bin2hex(random_bytes(6));
        $ledger = new \Baander\Containers\Resources\ResourceLedger(
            dirname($source) . '/' . basename($source) . '-frontend-resources.json',
            $docker,
            $this->connections->environment(),
        );
        $ledger->scoped(function () use ($ledger, $docker, $source, $image, $name): void {
            $ledger->create(\Baander\Containers\Resources\ResourceKind::Container, $name, [
                '--workdir', '/work/ui/web', $image,
                'sh', '-ec', 'yarn install --immutable && yarn build',
            ]);
            $docker->run(['cp', $source . '/.', $name . ':/work'], timeout: 300);
            $docker->run(['start', '--attach', $name], timeout: null, logPath: dirname($source) . '/' . basename($source) . '-frontend.log');
            if (trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $name])->stdout) !== '0') {
                throw new WorkflowError('Frontend build failed before activation.');
            }
            $docker->run(['cp', $name . ':/work/public/.', $source . '/public'], timeout: 300);
        }, $this->configuration->budgets->seconds('cleanup'));
    }
}
