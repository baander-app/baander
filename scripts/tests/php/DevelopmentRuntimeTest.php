<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/development-command-executor.php';

final class DevelopmentRuntimeTest extends TestCase
{
    private string $root;
    private string $docker;
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
        mkdir($this->root, 0700);
        $this->docker = $this->root . '/docker';
        copy(__DIR__ . '/Fixtures/development-runtime-docker.php', $this->docker);
        chmod($this->docker, 0700);
        $this->store = new StateStore($this->root);
        $this->instance = [
            'id' => 'persistent',
            'kind' => 'persistent',
            'project' => 'baander-persistent',
            'hostname' => 'dev.baander.app',
            'docker' => DevelopmentCommandExecutor::target($this->docker, 'owned', 'daemon'),
            'network' => 'baander-persistent',
            'media' => [],
            'volumes' => [],
        ];
        $this->store->save('persistent', $this->instance);
        file_put_contents($this->store->directory('persistent') . '/env.json', '{"REDIS_PASSWORD":"secret$literal"}');
        $this->runner = new RuntimeRecordingRunner();
        $this->runtime = new Runtime($this->store, $this->runner);
        mkdir($this->root . '/source/docker/dev', 0700, true);
        copy(dirname(__DIR__, 3) . '/docker/dev/frontend.conf.template', $this->root . '/source/docker/dev/frontend.conf.template');
        $this->release = ['revision' => 'revision', 'source' => $this->root . '/source', 'layout' => ['version' => 1, 'root' => '/var/www/html']];
        foreach (['app', 'worker', 'postgres', 'redis', 'nginx', 'tool'] as $role) {
            $this->release[$role . '_image'] = 'sha256:' . $role;
        }
        $this->release = DevelopmentCommandExecutor::release($this->release, $this->instance);
    }

    protected function tearDown(): void
    {
        new DevelopmentCommandExecutor()->execute(['rm', '-rf', '--', $this->root]);
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

    public function testSavedNondefaultLayoutDrivesRuntimeAndFrontend(): void
    {
        $this->release['layout'] = ['version' => 1, 'root' => '/opt/baander'];
        $config = $this->runtime->config($this->instance, $this->release);
        self::assertContains('/opt/baander/var', array_column($config['services']['app']['volumes'], 'target'));
        self::assertContains('/opt/baander/config/secrets', array_column($config['services']['app']['volumes'], 'target'));
        self::assertContains('/opt/baander/public', array_column($config['services']['nginx']['volumes'], 'target'));
        $instance = $this->runtime->write($this->instance, $this->release);
        $mounts = $this->runtime->config($instance, $this->release)['services']['nginx']['volumes'];
        $configuration = array_values(array_filter($mounts, static fn(array $mount): bool => $mount['target'] === '/etc/nginx/templates/default.conf.template'))[0]['source'];
        self::assertSame('/opt/baander', $config['services']['nginx']['environment']['APP_HOME']);
        self::assertSame(str_replace('include /etc/nginx/preview.conf;', '', file_get_contents($this->release['source'] . '/docker/dev/frontend.conf.template')), file_get_contents($configuration));
    }

    public function testApplicationContainerPreservesSavedMountsAndKeepsSecretsOutOfArguments(): void
    {
        mkdir($this->root . '/media$literal');
        $instance = array_replace($this->instance, [
            'media' => [['source' => $this->root . '/media$literal', 'target' => '/media/movies']],
            'volumes' => ['secrets' => 'retained-secrets'],
        ]);
        $release = $this->release;
        $release['layout'] = ['version' => 1, 'root' => '/opt/baander'];
        $container = $this->runtime->applicationContainer($instance, $release, ['php', 'bin/console', 'cache:warmup']);
        self::assertContains('type=bind,src=' . $this->root . '/media$literal,dst=/media/movies,readonly', $container['arguments']);
        self::assertContains('type=volume,src=retained-secrets,dst=/opt/baander/config/secrets', $container['arguments']);
        self::assertSame('secret$literal', $container['environment']['REDIS_PASSWORD']);
        self::assertNotContains('REDIS_PASSWORD=secret$literal', $container['arguments']);
        self::assertNotContains('secret$literal', $container['arguments']);
        self::assertContains('REDIS_PASSWORD', $container['arguments']);
        self::assertSame(['/opt/baander'], array_slice($container['arguments'], array_search('--workdir', $container['arguments'], true) + 1, 1));
        self::assertSame([$release['images']['app_image']['resolvedId'], 'php', 'bin/console', 'cache:warmup'], array_slice($container['arguments'], -4));
    }

    public function testWritePersistsComposeOwnershipAndInputIdentityBeforeProvisioning(): void
    {
        $instance = $this->runtime->write($this->instance, $this->release);
        $saved = $this->store->load('persistent');
        self::assertEquals($instance, $saved);
        self::assertSame($this->release['sourceDigest'], $saved['generated_inputs']['release']);
        self::assertSame(hash_file('sha256', $instance['compose_file']), $saved['generated_inputs']['files'][$instance['compose_file']]);
        $template = $this->store->directory('persistent') . '/frontend.conf.template';
        self::assertSame(hash_file('sha256', $template), $saved['generated_inputs']['files'][$template]);
        self::assertFalse(array_any($this->runner->calls, static fn(array $arguments): bool => in_array('up', $arguments, true)));
    }

    public function testNetworkOwnershipSurvivesIncompletePreviewSource(): void
    {
        mkdir($this->root . '/checkout');
        $instance = array_replace($this->instance, ['kind' => 'preview', 'worktree' => $this->root . '/checkout']);
        try {
            $this->runtime->ensureNetworks($instance, $this->release);
            self::fail('Expected incomplete source refusal');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('complete Baander worktree', $error->getMessage());
            $saved = $this->store->load('persistent');
            self::assertSame('baander-persistent', $saved['network']['name']);
            self::assertArrayHasKey('name', $saved['gateway']);
            self::assertFalse(array_any($this->runner->calls, static fn(array $arguments): bool => in_array('create', $arguments, true)));
        }
    }

    public function testSeederFailureRetainsReceiptWhenIndependentCleanupFails(): void
    {
        file_put_contents($this->root . '/runtime-fail-start', '1');
        file_put_contents($this->root . '/runtime-fail-remove', '1');
        try {
            $this->runtime->prepare($this->instance, $this->release);
            self::fail('Expected failed seeder and cleanup');
        } catch (\RuntimeException $error) {
            self::assertSame(7, $error->getCode());
            self::assertStringContainsString('removal denied', $error->getMessage());
            $receipts = glob($this->store->directory('persistent') . '/runtime-seed-public.json');
            self::assertCount(1, $receipts);
            $receipt = \Baander\Tooling\Filesystem\AtomicFile::readJson($receipts[0]);
            self::assertEquals($this->instance['docker'], $receipt['target']);
            $resource = $receipt['resources'][0];
            self::assertSame(hash('sha256', $resource['name']), $resource['id']);
            unlink($this->root . '/runtime-fail-start');
            unlink($this->root . '/runtime-fail-remove');
            $report = \Baander\Tooling\Resources\ResourceLedger::recover($receipts[0], \Baander\Tooling\Development\Environment::clean(), 10);
            self::assertTrue($report->successful(), $report->message());
            self::assertFileDoesNotExist($receipts[0]);
            self::assertSame([], glob($this->root . '/runtime-resource-*.json'));
        }
    }

    public function testStartRecordsFrontendAddressBeforeAppAndDownRetainsVolumes(): void
    {
        $instance = $this->runtime->write($this->instance, $this->release);
        $this->runtime->up($instance, $this->release);
        $config = json_decode(file_get_contents($instance['compose_file']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('172.20.0.3', $config['services']['app']['environment']['TRUSTED_PROXIES']);
        self::assertEquals($this->store->load('persistent')['generated_inputs'], $instance['generated_inputs']);
        self::assertSame(hash_file('sha256', $instance['compose_file']), $instance['generated_inputs']['files'][$instance['compose_file']]);
        $starts = array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('up', $args, true)));
        self::assertSame(['nginx', 'app'], array_map(static fn(array $args): string => $args[array_key_last($args)], $starts));
        $this->runtime->down($instance);
        $command = $this->runner->calls[array_key_last($this->runner->calls)];
        self::assertSame([$this->docker, '--host', 'unix:///var/run/docker.sock', 'compose'], array_slice($command, 0, 4));
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
            array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('create', $args, true))),
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
        new DevelopmentCommandExecutor()->execute(['/bin/sh', '-ec', 'chown() { :; }; ' . $script]);
        self::assertSame(['.', '..', 'current.js'], scandir($this->root . '/seed'));
        file_put_contents($this->root . '/seed/identity.key', 'retain');
        $script = $scripts['type=volume,src=baander-persistent-secrets,dst=/seed'];
        $script = str_replace(
            ['/var/www/html/config/secrets', '/seed'],
            [escapeshellarg($this->root . '/built'), escapeshellarg($this->root . '/seed')],
            $script,
        );
        new DevelopmentCommandExecutor()->execute(['/bin/sh', '-ec', 'chown() { :; }; ' . $script]);
        self::assertSame('retain', file_get_contents($this->root . '/seed/identity.key'));
    }
}

final class RuntimeRecordingRunner extends DevelopmentCommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];
    public function execute(
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
        if (in_array('create', $arguments, true) || in_array('start', $arguments, true)
            || in_array('{{.State.ExitCode}}', $arguments, true) || in_array('{{json .State}}', $arguments, true)) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
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
