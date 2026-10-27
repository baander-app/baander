<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentRuntimeTest extends TestCase
{
    private string $root;
    private StateStore $store;
    private Runtime $runtime;
    /** @var array<string,mixed> */
    private array $instance;
    /** @var array<string,mixed> */
    private array $release;
    private RuntimeRecordingRunner $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-runtime-' . bin2hex(random_bytes(8));
        $this->store = new StateStore($this->root);
        $this->instance = [
            'id' => 'persistent',
            'kind' => 'persistent',
            'project' => 'baander-persistent',
            'hostname' => 'dev.baander.app',
            'docker' => ['executable' => '/bin/docker', 'context' => 'owned', 'daemon_id' => 'daemon'],
            'network' => 'baander-persistent',
            'media' => [],
            'volumes' => [],
        ];
        $this->store->save('persistent', $this->instance);
        file_put_contents($this->store->directory('persistent') . '/env.json', '{"REDIS_PASSWORD":"secret$literal"}');
        $this->runner = new RuntimeRecordingRunner();
        $this->runtime = new Runtime($this->store, $this->runner);
        $this->release = ['revision' => 'revision'];
        foreach (['app', 'worker', 'postgres', 'redis', 'nginx', 'tool'] as $role) {
            $this->release[$role . '_image'] = 'sha256:' . $role;
        }
    }

    protected function tearDown(): void
    {
        new Runner()->run(['rm', '-rf', '--', $this->root]);
    }

    public function testComposePreservesStorageNetworkMapsAndEscapedEnvironment(): void
    {
        $config = $this->runtime->config($this->instance, $this->release);
        $postgres = $config['services']['postgres'];
        self::assertSame('/var/lib/postgresql/data/baanderdev', $postgres['environment']['PGDATA']);
        self::assertSame(['/var/lib/postgresql:rw,noexec,nosuid,size=1m,mode=0755'], $postgres['tmpfs']);
        self::assertSame('secret$$literal', $config['services']['redis']['environment']['REDIS_PASSWORD']);
        self::assertSame('{}', json_encode($config['services']['app']['networks']['private']));
        self::assertArrayNotHasKey('gateway', $config['services']['app']['networks']);
        foreach (['/var/lib', '/var/lib/postgresql'] as $target) {
            $instance = array_replace($this->instance, ['postgres_mount_target' => $target, 'pgdata' => $target . '/retained']);
            $postgres = $this->runtime->config($instance, $this->release)['services']['postgres'];
            self::assertArrayNotHasKey('tmpfs', $postgres);
            self::assertSame($target, $postgres['volumes'][0]['target']);
        }
    }

    public function testStartRecordsFrontendAddressBeforeAppAndDownRetainsVolumes(): void
    {
        $instance = $this->runtime->write($this->instance, $this->release);
        $this->runtime->up($instance, $this->release);
        $config = json_decode(file_get_contents($instance['compose_file']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('172.20.0.3', $config['services']['app']['environment']['TRUSTED_PROXIES']);
        $starts = array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('up', $args, true)));
        self::assertSame(['nginx', 'app'], array_map(static fn(array $args): string => $args[array_key_last($args)], $starts));
        $this->runtime->down($instance);
        $command = $this->runner->calls[array_key_last($this->runner->calls)];
        self::assertSame(['/bin/docker', '--context', 'owned', 'compose'], array_slice($command, 0, 4));
        self::assertSame(['down', '--remove-orphans'], array_slice($command, -2));
        self::assertNotContains('--volumes', $command);
    }

    public function testPreviewCreatesEmptyMountpointsAndKeepsWorktreeReadOnly(): void
    {
        mkdir($this->root . '/checkout');
        $instance = array_replace($this->instance, ['kind' => 'preview', 'worktree' => $this->root . '/checkout']);
        $model = $this->runtime->config($instance, $this->release);
        self::assertTrue($model['services']['app']['volumes'][0]['read_only']);
        self::assertSame($instance['worktree'], $model['services']['vite']['volumes'][0]['source']);
        $this->runtime->prepare($instance, $this->release);
        foreach (['vendor', 'var', 'storage', 'config/secrets', 'ui/web/node_modules'] as $relative) {
            self::assertDirectoryExists($instance['worktree'] . '/' . $relative);
        }
        self::assertSame(['.', '..'], scandir($instance['worktree'] . '/ui/web/node_modules'));
        $installs = array_filter($this->runner->calls, static fn(array $args): bool => in_array('install', $args, true));
        self::assertCount(1, $installs);
        self::assertContains('type=bind,src=' . $instance['worktree'] . ',dst=/var/www/html,readonly', array_values($installs)[0]);
    }

    public function testPreviewMountpointSymlinkAndTraversalRefusedBeforeHelpers(): void
    {
        mkdir($this->root . '/checkout');
        mkdir($this->root . '/outside');
        symlink($this->root . '/outside', $this->root . '/checkout/ui');
        $instance = array_replace($this->instance, ['kind' => 'preview', 'worktree' => $this->root . '/checkout']);
        try {
            $this->runtime->prepare($instance, $this->release);
            self::fail('Expected symlink refusal');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('symlink', $error->getMessage());
        }
        unlink($this->root . '/checkout/ui');
        file_put_contents(
            $this->store->directory('persistent') . '/env.json',
            '{"CONVERT_STORAGE_PATH":"/var/www/html/../outside-preview"}',
        );
        try {
            $this->runtime->prepare($instance, $this->release);
            self::fail('Expected traversal refusal');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('parent', $error->getMessage());
        }
        self::assertSame(
            [],
            array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('run', $args, true))),
        );
        self::assertDirectoryDoesNotExist($this->root . '/outside-preview');
    }

    public function testPreviewDaemonSourceMismatchPreventsStart(): void
    {
        mkdir($this->root . '/checkout/ui/web', 0700, true);
        file_put_contents($this->root . '/checkout/composer.lock', '{}');
        file_put_contents($this->root . '/checkout/ui/web/package.json', '{}');
        $instance = array_replace($this->instance, ['kind' => 'preview', 'worktree' => $this->root . '/checkout']);
        $this->expectExceptionMessage('Docker sees different preview source');
        $this->runtime->ensureNetworks($instance, $this->release);
    }

    public function testRefreshRemovesObsoletePublicFilesButRetainsSecretIdentity(): void
    {
        $this->runtime->prepare($this->instance, $this->release);
        $scripts = [];
        foreach ($this->runner->calls as $args) {
            if (in_array('--mount', $args, true)) {
                $scripts[$args[array_search('--mount', $args, true) + 1]] = $args[array_key_last($args)];
            }
        }
        mkdir($this->root . '/seed');
        mkdir($this->root . '/built');
        file_put_contents($this->root . '/seed/obsolete.js', 'old');
        file_put_contents($this->root . '/built/current.js', 'new');
        $script = $scripts['type=volume,src=baander-persistent-public,dst=/seed'];
        $script = str_replace(
            ['/var/www/html/public', '/seed'],
            [escapeshellarg($this->root . '/built'), escapeshellarg($this->root . '/seed')],
            $script,
        );
        new Runner()->run(['/bin/sh', '-ec', 'chown() { :; }; ' . $script]);
        self::assertSame(['.', '..', 'current.js'], scandir($this->root . '/seed'));
        file_put_contents($this->root . '/seed/identity.key', 'retain');
        $script = $scripts['type=volume,src=baander-persistent-secrets,dst=/seed'];
        $script = str_replace(
            ['/var/www/html/config/secrets', '/seed'],
            [escapeshellarg($this->root . '/built'), escapeshellarg($this->root . '/seed')],
            $script,
        );
        new Runner()->run(['/bin/sh', '-ec', 'chown() { :; }; ' . $script]);
        self::assertSame('retain', file_get_contents($this->root . '/seed/identity.key'));
    }
}

final class RuntimeRecordingRunner extends Runner
{
    /** @var list<list<string>> */
    public array $calls = [];
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
        $this->calls[] = $arguments;
        $output = '{}';
        if (in_array('info', $arguments, true)) {
            $output = '{"ID":"daemon"}';
        } elseif (in_array('ps', $arguments, true)) {
            $output = 'nginx-container';
        } elseif (in_array('inspect', $arguments, true) && !in_array('network', $arguments, true)) {
            $output = '{"baander-persistent":{"IPAddress":"172.20.0.3"}}';
        } elseif (in_array('network', $arguments, true) && in_array('inspect', $arguments, true)) {
            $name = $arguments[array_key_last($arguments)];
            $owner = str_starts_with($name, 'baander-development-gateway-') ? 'gateway-' . substr($name, strlen('baander-development-gateway-')) : 'persistent';
            $output = json_encode(
                [[
                    'Labels' => ['app.baander.development.owner' => $owner],
                    'IPAM' => ['Config' => [['Subnet' => '172.20.0.0/16']]],
                ]],
                JSON_THROW_ON_ERROR,
            );
        }
        return new ProcessResult(0, null, false, false, $output, '', 0);
    }
}
