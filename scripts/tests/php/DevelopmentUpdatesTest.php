<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Build\SourceUnavailable;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\Updater;
use Baander\Tooling\Development\Workers;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

#[AllowMockObjectsWithoutExpectations]
final class DevelopmentUpdatesTest extends TestCase
{
    private string $root;
    private StateStore $store;
    private Updater&MockObject $updater;
    private Builder&MockObject $builder;
    private Runtime&MockObject $runtime;
    private Workers&MockObject $workers;
    private DaemonImagesExecutor $daemon;
    /** @var array<string,mixed> */
    private array $instance;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-update-' . bin2hex(random_bytes(8));
        $this->store = new StateStore($this->root);
        $this->instance = [
            'id' => 'persistent',
            'kind' => 'persistent',
            'repository' => '/disposable/remote',
            'phase' => 'ready',
            'running_revision' => 'old',
            'release' => ['revision' => 'old'],
            'readiness' => ['web_ready' => true],
        ];
        $this->instance['docker'] = DevelopmentCommandExecutor::target();
        $this->instance['release'] = DevelopmentCommandExecutor::release($this->instance['release'], $this->instance);
        $this->store->save('persistent', $this->instance);
        $this->store->setEnabled('persistent', true);
        $this->updater = $this->getMockBuilder(Updater::class)->setConstructorArgs([$this->store, $this->daemon = new DaemonImagesExecutor()])->onlyMethods(['head', 'activate'])->getMock();
        $this->updater->builder = $this->builder = $this->createMock(Builder::class);
        $this->updater->runtime = $this->runtime = $this->createMock(Runtime::class);
        $this->updater->workers = $this->workers = $this->createMock(Workers::class);
        $this->runtime->method('running')->willReturn(true);
        $this->workers->method('running')->willReturn(true);
    }

    protected function tearDown(): void
    {
        new DevelopmentCommandExecutor()->execute(['rm', '-rf', '--', $this->root]);
    }

    public function testInvalidSavedMediaFailsBeforeUpdatingState(): void
    {
        $this->instance['media'] = [['source' => $this->root . '/missing-media', 'target' => '/media/movies']];
        $this->store->save('persistent', $this->instance);
        $path = $this->store->directory('persistent') . '/state.json';
        $original = file_get_contents($path);
        $this->runtime->method('environment')->willReturn([]);
        $this->updater->expects(self::never())->method('head');
        $this->builder->expects(self::never())->method('prepare');
        try {
            $this->updater->update('persistent');
            self::fail('Invalid source media reached update mutation.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('media directory', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($path));
    }

    public function testHealthySameRevisionOnlyObservesContainers(): void
    {
        $this->updater->method('head')->willReturn('old');
        $this->builder->expects(self::never())->method('prepare');
        $this->updater->expects(self::never())->method('activate');
        $this->runtime->expects(self::never())->method('ready');
        $this->workers->expects(self::never())->method('ready');
        $this->updater->update('persistent', automatic: true);
        self::assertSame('old', $this->store->load('persistent')['detected_revision']);
    }

    public function testDisabledAutomaticTickDoesNothing(): void
    {
        $this->store->setEnabled('persistent', false);
        $this->updater->expects(self::never())->method('head');
        $this->builder->expects(self::never())->method('prepare');
        $this->updater->update('persistent', automatic: true);
    }

    public function testFailedBuildRetainsRunningRevisionAndSkipsSameHead(): void
    {
        $this->updater->method('head')->willReturn('new');
        $this->builder->expects(self::once())->method('prepare')->willThrowException(new WorkflowError('build failed'));
        try {
            $this->updater->update('persistent');
            self::fail('Expected failure');
        } catch (WorkflowError) {
            $saved = $this->store->load('persistent');
            self::assertSame('old', $saved['running_revision']);
            self::assertSame('new', $saved['failed_revision']);
            self::assertSame('preparation_failed', $saved['phase']);
        }
        $this->updater->update('persistent', automatic: true);
    }

    public function testDisableDuringPreparationPreventsActivation(): void
    {
        $this->updater->method('head')->willReturn('new');
        $this->builder->method('prepare')->willReturnCallback(function (array &$instance): array {
            $this->store->setEnabled('persistent', false);
            return ['revision' => 'new'];
        });
        $this->updater->expects(self::never())->method('activate');
        $this->updater->update('persistent', automatic: true);
        self::assertSame('old', $this->store->load('persistent')['running_revision']);
        self::assertSame('ready', $this->store->load('persistent')['phase']);
    }

    public function testSourceFailureBacksOffAndRetriesSameRevision(): void
    {
        $this->updater->method('head')->willReturn('new');
        $attempts = 0;
        $this->builder->expects(self::exactly(2))->method('prepare')->willReturnCallback(static function (array &$instance) use (&$attempts): array {
            if (++$attempts === 1) {
                throw new SourceUnavailable('network');
            }
            return ['revision' => 'new'];
        });
        $this->updater->expects(self::once())->method('activate');
        try {
            $this->updater->update('persistent', automatic: true);
            self::fail('Expected source failure');
        } catch (SourceUnavailable) {
            $saved = $this->store->load('persistent');
            self::assertSame('ready', $saved['phase']);
            self::assertSame('old', $saved['running_revision']);
            self::assertArrayNotHasKey('failed_revision', $saved);
            self::assertGreaterThan(microtime(true), $saved['next_check_at']);
        }
        $this->updater->update('persistent', automatic: true);
        $saved['next_check_at'] = 0;
        $this->store->save('persistent', $saved);
        $this->updater->update('persistent', automatic: true);
    }

    public function testSuccessfulActivationDetectsNextMerge(): void
    {
        $this->updater->method('head')->willReturn('new', 'newer');
        $this->builder->method('prepare')->willReturn(['revision' => 'new']);
        $this->updater->expects(self::once())->method('activate');
        $this->updater->update('persistent');
        self::assertSame('newer', $this->store->load('persistent')['detected_revision']);
    }

    public function testUncertainMutationBecomesBlockedWithoutBuild(): void
    {
        $this->store->save('persistent', array_replace($this->instance, ['phase' => 'database_mutating']));
        $this->builder->expects(self::never())->method('prepare');
        try {
            $this->updater->update('persistent');
            self::fail('Expected explicit recovery');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('explicit recovery', $error->getMessage());
            self::assertSame('blocked', $this->store->load('persistent')['phase']);
        }
    }

    public function testStoppedSameRevisionReusesArtifactsAndClearsStaleReadinessOnFailure(): void
    {
        $this->updater->runtime = $this->runtime = $this->createMock(Runtime::class);
        $this->runtime->method('running')->willReturn(false);
        $this->updater->method('head')->willReturn('old');
        $this->builder->expects(self::never())->method('prepare');
        $this->updater->expects(self::once())->method('activate')->willReturnCallback(function (array &$instance, array $release): void {
            self::assertEquals($this->instance['release'], $release);
            $this->updater->save($instance, ['phase' => 'preflight']);
            throw new WorkflowError('dependency unavailable');
        });
        try {
            $this->updater->update('persistent', automatic: true);
            self::fail('Expected preflight failure');
        } catch (WorkflowError) {
            $saved = $this->store->load('persistent');
            self::assertNull($saved['readiness']);
            self::assertNull($saved['failed_revision']);
            self::assertNotSame('ready', $saved['phase']);
        }
        // Every image of the saved release was looked up in the daemon before it was reused.
        self::assertEqualsCanonicalizing(
            array_values(array_unique(array_column($this->instance['release']['images'], 'resolvedId'))),
            $this->daemon->inspected,
        );
    }

    /** @return iterable<string,array{string}> */
    public static function savedReleaseImages(): iterable
    {
        yield 'postgres' => ['postgres_image'];
        yield 'redis' => ['redis_image'];
        yield 'application' => ['app_image'];
    }

    #[DataProvider('savedReleaseImages')]
    public function testStoppedSameRevisionPreparesAReleaseWhenASavedImageLeftTheDaemon(string $key): void
    {
        $this->updater->runtime = $this->runtime = $this->createMock(Runtime::class);
        $this->runtime->method('running')->willReturn(false);
        $this->updater->method('head')->willReturn('old');
        // A stopped instance holds no container on its images, so the daemon can lose them.
        $this->daemon->missing = [$this->instance['release']['images'][$key]['resolvedId']];
        $prepared = ['revision' => 'old', 'source' => 'rebuilt'];
        $this->builder->expects(self::once())->method('prepare')->willReturn($prepared);
        $this->updater->expects(self::once())->method('activate')->willReturnCallback(static function (array &$instance, array $release) use ($prepared): void {
            self::assertSame($prepared, $release);
        });
        $this->updater->update('persistent', automatic: true);
        self::assertSame($prepared, $this->store->load('persistent')['prepared_release']);
    }
}

/** Answers the daemon's image lookups, so no test reaches a real Docker daemon. */
final class DaemonImagesExecutor extends DevelopmentCommandExecutor
{
    /** @var list<string> Images the daemon no longer holds. */
    public array $missing = [];
    /** @var list<string> */
    public array $inspected = [];

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
        if (array_slice($arguments, 3, 2) !== ['image', 'inspect']) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $this->inspected[] = $image = end($arguments);
        return in_array($image, $this->missing, true)
            ? new ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $image, 0)
            : new ProcessResult(0, null, false, false, $image, '', 0);
    }
}
