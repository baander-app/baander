<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentImagePinsTest extends TestCase
{
    private string $root;
    private ImageRecordingRunner $runner;
    private Builder $builder;
    /** @var array<string,mixed> */
    private array $instance;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-pins-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source', 0700, true);
        $runner = new Runner();
        $runner->run(['git', 'init', '-b', 'master', $this->root . '/source']);
        file_put_contents($this->root . '/source/code.php', '<?php');
        mkdir($this->root . '/source/packages/baander-tooling/resources', 0700, true);
        file_put_contents($this->root . '/source/packages/baander-tooling/resources/application-layout.json', '{"version":1,"root":"/srv/baander"}');
        $this->runner = new ImageRecordingRunner();
        $store = new StateStore($this->root . '/state');
        $this->instance = [
            'id' => 'preview-0123456789abcdef',
            'kind' => 'preview',
            'project' => 'owned-project',
            'worktree' => $this->root . '/source',
            'docker' => ['executable' => '/fake/docker', 'context' => 'owned', 'daemon_id' => 'daemon'],
        ];
        $store->save($this->instance['id'], $this->instance);
        $this->builder = new Builder($store, $this->runner, ToolingConfiguration::resolve(['images' => ['developmentRedis' => ['reference' => 'registry.baander.app/redis:new']]]));
    }

    protected function tearDown(): void
    {
        new Runner()->run(['rm', '-rf', '--', $this->root]);
    }

    public function testEveryFinalImageAndBuildTagIsUniquePerSnapshot(): void
    {
        $first = $this->builder->prepare($this->instance);
        $second = $this->builder->prepare($this->instance);
        self::assertCount(7, $first['image_pins']);
        foreach ($first['image_pins'] as $key => $pin) {
            self::assertStringContainsString('owned-project-' . basename($first['source']), $pin);
            self::assertNotSame($pin, $second['image_pins'][$key]);
            self::assertContains(['/fake/docker', '--context', 'owned', 'image', 'tag', $first[$key], $pin], $this->runner->calls);
        }
        foreach ([$first, $second] as $release) {
            self::assertSame(['version' => 1, 'root' => '/srv/baander'], $release['layout']);
            self::assertContains(
                ['/fake/docker', '--context', 'owned', 'image', 'inspect', '--format', '{{.Id}}', 'baander-development-postgres:' . $release['revision'] . '-' . basename($release['source'])],
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
        self::assertNotContains(['/fake/docker', '--context', 'owned', 'pull', 'baander-database:latest'], $this->runner->calls);
    }

    public function testExplicitPostgresPreferencePullsTheSelectedSource(): void
    {
        $builder = new Builder(new StateStore($this->root . '/state'), $this->runner, ToolingConfiguration::resolve([
            'images' => ['postgres' => ['reference' => 'registry.baander.app/postgres:qualification']],
        ]));
        $release = $builder->prepare($this->instance);
        self::assertContains(['/fake/docker', '--context', 'owned', 'pull', 'registry.baander.app/postgres:qualification'], $this->runner->calls);
        self::assertSame('registry.baander.app/postgres:qualification', $release['image_selections']['postgres_image']['reference']);
        self::assertFalse((bool) array_filter($this->runner->calls, static fn(array $args): bool => in_array($release['source'] . '/docker/postgres/Dockerfile', $args, true)));
    }

    public function testExistingStorageImagesOverrideNewBuildBeforePinning(): void
    {
        $this->instance['release'] = ['postgres_image' => 'previous-postgres', 'redis_image' => 'previous-redis'];
        $release = $this->builder->prepare($this->instance);
        self::assertSame('previous-postgres', $release['postgres_image']);
        self::assertSame('previous-redis', $release['redis_image']);
        self::assertTrue($release['image_selections']['redis_image']['retained']);
        self::assertNotContains(['/fake/docker', '--context', 'owned', 'pull', 'registry.baander.app/redis:new'], $this->runner->calls);
        self::assertSame('registry.baander.app/redis:new', $release['image_selections']['redis_image']['reference']);
        $this->instance['postgres_image'] = 'adopted-postgres';
        $this->instance['redis_image'] = 'adopted-redis';
        $release = $this->builder->prepare($this->instance);
        self::assertSame('adopted-postgres', $release['postgres_image']);
        self::assertSame('adopted-redis', $release['redis_image']);
    }

    public function testFailedFrontendIsRemovedAndNeverStartsAppBuild(): void
    {
        $this->runner->frontendExit = '1';
        try {
            $this->builder->prepare($this->instance);
            self::fail('Expected frontend build failure');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('Frontend build failed', $error->getMessage());
            self::assertTrue((bool) array_filter(
                $this->runner->calls,
                static fn(array $args): bool => in_array('rm', $args, true) && in_array('--force', $args, true),
            ));
            self::assertFalse((bool) array_filter($this->runner->calls, static fn(array $args): bool => in_array('dev-runtime', $args, true)));
        }
    }

    public function testInterruptedFrontendCreationStillRemovesNamedContainer(): void
    {
        $this->runner->interruptCreate = true;
        try {
            $this->builder->prepare($this->instance);
            self::fail('Expected interrupted creation');
        } catch (WorkflowError) {
            $creates = array_values(array_filter($this->runner->calls, static fn(array $args): bool => in_array('create', $args, true)));
            self::assertCount(1, $creates);
            $name = $creates[0][array_search('--name', $creates[0], true) + 1];
            self::assertContains(['/fake/docker', '--context', 'owned', 'rm', '--force', $name], $this->runner->calls);
        }
    }
}

final class ImageRecordingRunner extends Runner
{
    /** @var list<list<string>> */
    public array $calls = [];
    public string $frontendExit = '0';
    public bool $interruptCreate = false;
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
        if ($arguments[0] !== '/fake/docker') {
            return parent::run($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $this->calls[] = $arguments;
        if ($this->interruptCreate && in_array('create', $arguments, true)) {
            throw new WorkflowError('Container creation confirmation lost');
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
