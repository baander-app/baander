<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Build;

use Baander\Tooling\Build\BuildRequest;
use Baander\Tooling\Configuration\{ApplicationLayout, ToolingConfiguration};
use Baander\Tooling\Development\Docker;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Filesystem\PrivateDirectory;

class Builder
{
    public function __construct(private readonly ?StateStore $store, private readonly Runner $runner, private readonly ToolingConfiguration $configuration) {}

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function prepare(array &$instance): array
    {
        if ($this->store === null) {
            throw new WorkflowError('Release preparation requires an instance state store.');
        }
        $docker = new Docker($this->runner, $instance['docker']);
        $docker->validate();
        $builds = $this->store->directory($instance['id']) . '/builds';
        PrivateDirectory::prepare($builds);
        $source = $builds . '/' . bin2hex(random_bytes(16));
        if ($instance['kind'] === 'persistent') {
            [, $revision] = Source::checkoutRevision($this->runner, $instance['repository'], $this->store->directory($instance['id']) . '/managed', $source);
        } else {
            $revision = Source::snapshotWorktree($this->runner, $instance['worktree'], $source);
        }
        $layout = ApplicationLayout::load($source . '/packages/baander-tooling/resources/application-layout.json');
        $instance['target_revision'] = $revision;
        $this->store->save($instance['id'], $instance);
        $release = ['revision' => $revision, 'source' => $source, 'layout' => $layout->toArray()];
        $release['tool_image'] = $this->build($docker, $source, 'dev-tools', $revision);
        $this->frontend($docker, $source, $release['tool_image']);
        $release['app_image'] = $this->build($docker, $source, $instance['kind'] === 'persistent' ? 'production' : 'dev-runtime', $revision);
        $release['worker_image'] = $this->build($docker, $source, 'worker', $revision);
        $release['operator_image'] = $this->build($docker, $source, 'dev-operator', $revision);
        foreach (['postgres_image' => 'postgres', 'nginx_image' => 'nginx', 'redis_image' => 'developmentRedis'] as $key => $role) {
            $selection = $this->configuration->images->role($role);
            $retained = in_array($role, ['postgres', 'developmentRedis'], true) ? ($instance[$key] ?? $instance['release'][$key] ?? null) : null;
            if ($retained !== null) {
                $release[$key] = $retained;
            } elseif ($role === 'postgres' && $this->configuration->provenance['images.postgres.reference'] === 'defaults') {
                $tag = 'baander-development-postgres:' . $revision . '-' . basename($source);
                $docker->run(['build', '--file', $source . '/docker/postgres/Dockerfile', '--tag', $tag, $source . '/docker'], timeout: null, logPath: $builds . '/' . basename($source) . '-postgres.log');
                $release[$key] = $this->imageId($docker, $tag);
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
        return $release;
    }

    public function imageId(Docker $docker, string $name): string
    {
        return trim($docker->run(['image', 'inspect', '--format', '{{.Id}}', $name])->stdout);
    }

    public function build(Docker $docker, string $source, string $target, string $revision): string
    {
        $tag = 'baander-development-' . $target . ':' . $revision . '-' . basename($source);
        $request = BuildRequest::fromSource($source, $target, $revision, $tag, $this->configuration->images->role('operatorHelper'));
        $docker->run($request->arguments, timeout: null, logPath: dirname($source) . '/' . basename($source) . '-' . $target . '.log');
        return $request->imageId($docker->run(['image', 'inspect', $tag])->stdout);
    }

    public function frontend(Docker $docker, string $source, string $image): void
    {
        $name = 'baander-assets-' . bin2hex(random_bytes(6));
        try {
            $docker->run([
                'create',
                '--name',
                $name,
                '--workdir',
                '/work/ui/web',
                $image,
                'sh',
                '-ec',
                'yarn install --immutable && yarn build',
            ]);
            $docker->run(['cp', $source . '/.', $name . ':/work'], timeout: 300);
            $docker->run(['start', '--attach', $name], timeout: null, logPath: dirname($source) . '/' . basename($source) . '-frontend.log');
            if (trim($docker->run(['inspect', '--format', '{{.State.ExitCode}}', $name])->stdout) !== '0') {
                throw new WorkflowError('Frontend build failed before activation.');
            }
            $docker->run(['cp', $name . ':/work/public/.', $source . '/public'], timeout: 300);
        } finally {
            $docker->run(['rm', '--force', $name], check: false);
        }
    }
}
