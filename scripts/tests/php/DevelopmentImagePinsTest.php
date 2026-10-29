<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentImagePinsTest extends TestCase
{
    private string $root;
    private string $docker;
    private ImageRecordingRunner $runner;
    private Builder $builder;
    /** @var array<string,mixed> */
    private array $instance;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-pins-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source', 0700, true);
        $runner = new DevelopmentCommandExecutor();
        $runner->execute(['git', 'init', '-b', 'master', $this->root . '/source']);
        file_put_contents($this->root . '/source/code.php', '<?php');
        file_put_contents($this->root . '/source/Dockerfile', 'ARG APP_HOME');
        mkdir($this->root . '/source/packages/baander-tooling/resources', 0700, true);
        file_put_contents($this->root . '/source/packages/baander-tooling/resources/application-layout.json', '{"version":1,"root":"/srv/baander"}');
        $this->docker = $this->root . '/docker';
        copy(__DIR__ . '/Fixtures/development-image-docker.php', $this->docker);
        chmod($this->docker, 0700);
        $this->runner = new ImageRecordingRunner($this->docker);
        $store = new StateStore($this->root . '/state');
        $this->instance = [
            'id' => 'preview-0123456789abcdef',
            'kind' => 'preview',
            'project' => 'owned-project',
            'worktree' => $this->root . '/source',
            'docker' => DevelopmentCommandExecutor::target($this->docker, 'owned', 'daemon'),
        ];
        $store->save($this->instance['id'], $this->instance);
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($store->directory($this->instance['id']) . '/env.json', ['APP_SECRET' => 'fixture']);
        $this->builder = new Builder($store, $this->runner, ToolingConfiguration::resolve(['images' => ['developmentRedis' => ['reference' => 'registry.baander.app/redis:new']]]));
    }

    protected function tearDown(): void
    {
        new DevelopmentCommandExecutor()->execute(['rm', '-rf', '--', $this->root]);
    }

    public function testEveryFinalImageAndBuildTagIsUniquePerSnapshot(): void
    {
        $first = $this->builder->prepare($this->instance);
        $second = $this->builder->prepare($this->instance);
        self::assertCount(7, $first['image_pins']);
        foreach ($first['image_pins'] as $key => $pin) {
            self::assertArrayNotHasKey($key, $first);
            self::assertArrayNotHasKey($key, $second);
            self::assertStringContainsString('owned-project-' . basename($first['source']), $pin);
            self::assertNotSame($pin, $second['image_pins'][$key]);
            self::assertContains([$this->docker, '--host', 'unix:///var/run/docker.sock', 'image', 'tag', $first['images'][$key]['resolvedId'], $pin], $this->runner->calls);
        }
        foreach ([$first, $second] as $release) {
            self::assertSame(['version' => 1, 'root' => '/srv/baander'], $release['layout']);
            self::assertContains(
                [$this->docker, '--host', 'unix:///var/run/docker.sock', 'image', 'inspect', '--format', '{{.Id}}', 'baander-development-postgres:' . $release['revision'] . '-' . basename($release['source'])],
                $this->runner->calls,
            );
        }
        self::assertTrue((bool) array_filter(
            $this->runner->calls,
            static fn(array $args): bool => in_array('cp', $args, true) && in_array($first['source'] . '/.', $args, true),
        ));
    }

    public function testDefaultPostgresIsBuiltFromSelectedSourceInsteadOfPulled(): void
    {
        $release = $this->builder->prepare($this->instance);
        self::assertTrue((bool) array_filter($this->runner->calls, static fn(array $args): bool => in_array($release['source'] . '/docker/postgres/Dockerfile', $args, true)));
        self::assertNotContains([$this->docker, '--host', 'unix:///var/run/docker.sock', 'pull', 'baander-database:latest'], $this->runner->calls);
    }

    public function testExplicitPostgresPreferencePullsTheSelectedSource(): void
    {
        $builder = new Builder(new StateStore($this->root . '/state'), $this->runner, ToolingConfiguration::resolve([
            'images' => ['postgres' => ['reference' => 'registry.baander.app/postgres:qualification']],
        ]));
        $release = $builder->prepare($this->instance);
        self::assertContains([$this->docker, '--host', 'unix:///var/run/docker.sock', 'pull', 'registry.baander.app/postgres:qualification'], $this->runner->calls);
        self::assertSame('registry.baander.app/postgres:qualification', $release['image_selections']['postgres_image']['reference']);
        self::assertFalse((bool) array_filter($this->runner->calls, static fn(array $args): bool => in_array($release['source'] . '/docker/postgres/Dockerfile', $args, true)));
    }

    public function testExistingStorageImagesOverrideNewBuildBeforePinning(): void
    {
        $this->instance['release'] = DevelopmentCommandExecutor::release(['postgres_image' => 'previous-postgres', 'redis_image' => 'previous-redis'], $this->instance);
        $release = $this->builder->prepare($this->instance);
        self::assertSame('previous-postgres', $release['images']['postgres_image']['resolvedId']);
        self::assertSame('previous-redis', $release['images']['redis_image']['resolvedId']);
        self::assertSame($release['image_pins']['redis_image'], $release['images']['redis_image']['reference']);
        self::assertTrue($release['image_selections']['redis_image']['retained']);
        self::assertNotContains([$this->docker, '--host', 'unix:///var/run/docker.sock', 'pull', 'registry.baander.app/redis:new'], $this->runner->calls);
        self::assertSame('registry.baander.app/redis:new', $release['image_selections']['redis_image']['reference']);
        $this->instance['postgres_image'] = 'adopted-postgres';
        $this->instance['redis_image'] = 'adopted-redis';
        $release = $this->builder->prepare($this->instance);
        self::assertSame('adopted-postgres', $release['images']['postgres_image']['resolvedId']);
        self::assertSame('adopted-redis', $release['images']['redis_image']['resolvedId']);
    }

    public function testFailedFrontendIsRemovedAndNeverStartsAppBuild(): void
    {
        $this->runner->frontendExit = '1';
        try {
            $this->builder->prepare($this->instance);
            self::fail('Expected frontend build failure');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('Frontend build failed', $error->getMessage());
            self::assertFileDoesNotExist($this->root . '/resource.json');
            self::assertSame([], glob($this->root . '/state/*/builds/*-frontend-resources.json'));

            self::assertFalse((bool) array_filter($this->runner->calls, static fn(array $args): bool => in_array('dev-runtime', $args, true)));
        }
    }

    public function testInterruptedFrontendCreationStillRemovesNamedContainer(): void
    {
        $this->runner->interruptCreate = true;
        try {
            $this->builder->prepare($this->instance);
            self::fail('Expected interrupted creation');
        } catch (\Baander\Tooling\Process\CommandFailure $error) {
            self::assertSame(9, $error->getCode());
            $creates = array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('create', $args, true)));
            self::assertCount(1, $creates);
            $name = $creates[0][array_search('--name', $creates[0], true) + 1];
            self::assertFileDoesNotExist($this->root . '/resource.json');
            $calls = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root . '/resource-calls.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertContains(['rm', '--force', '--volumes', '--', hash('sha256', $name)], $calls);
            self::assertSame([], glob($this->root . '/state/*/builds/*-frontend-resources.json'));

        }
    }

    public function testFailedFrontendCleanupRetainsIdentityForIndependentRecovery(): void
    {
        $this->runner->frontendExit = '1';
        file_put_contents($this->root . '/fail-remove', '1');
        try {
            $this->builder->prepare($this->instance);
            self::fail('Expected frontend and cleanup failure');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Frontend build failed', $error->getMessage());
            self::assertStringContainsString('removal denied', $error->getMessage());
            $receipts = glob($this->root . '/state/*/builds/*-frontend-resources.json');
            self::assertCount(1, $receipts);
            $receipt = \Baander\Tooling\Filesystem\AtomicFile::readJson($receipts[0]);
            $resource = $receipt['resources'][0];
            self::assertSame(hash('sha256', $resource['name']), $resource['id']);
            self::assertEquals($this->instance['docker'], $receipt['target']);
            self::assertFileExists($this->root . '/resource.json');
            unlink($this->root . '/fail-remove');
            $report = \Baander\Tooling\Resources\ResourceLedger::recover($receipts[0], \Baander\Tooling\Development\Environment::clean(), 10);
            self::assertTrue($report->successful(), $report->message());
            self::assertFileDoesNotExist($this->root . '/resource.json');
            self::assertFileDoesNotExist($receipts[0]);
        }
    }
}

final class ImageRecordingRunner extends DevelopmentCommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];
    public string $frontendExit = '0';
    public bool $interruptCreate = false;
    public function __construct(private readonly string $docker) {}
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
        if ($arguments[0] !== $this->docker) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $this->calls[] = $arguments;
        if ($this->interruptCreate && in_array('create', $arguments, true)) {
            file_put_contents(dirname($this->docker) . '/fail-create', '1');
        }
        if (in_array('create', $arguments, true) || in_array('info', $arguments, true)) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        if (in_array('image', $arguments, true) && in_array('inspect', $arguments, true) && !in_array('--format', $arguments, true)) {
            return new ProcessResult(0, null, false, false, json_encode([[
                'Id' => 'sha256:' . hash('sha256', implode(' ', $arguments)),
                'Config' => ['Env' => ['APP_HOME=/srv/baander'], 'WorkingDir' => '/srv/baander', 'Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/srv/baander"}']],
            ]], JSON_THROW_ON_ERROR), '', 0);
        }
        $output = in_array('info', $arguments, true) ? '{"ID":"daemon"}' : (in_array('{{.State.ExitCode}}', $arguments, true) ? $this->frontendExit : 'sha256:' . hash('sha256', implode(' ', $arguments)));
        return new ProcessResult(0, null, false, false, $output, '', 0);
    }
}
