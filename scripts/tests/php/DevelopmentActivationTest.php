<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Development\Database;
use Baander\Tooling\Development\Libraries;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\Runtime;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\Updater;
use Baander\Tooling\Development\Workers;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentActivationTest extends TestCase
{
    private string $root;
    private StateStore $store;
    private Updater $updater;
    /** @var array<string,mixed> */
    private array $instance;
    /** @var array<string,mixed> */
    private array $old;
    /** @var array<string,mixed> */
    private array $new;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-activation-' . bin2hex(random_bytes(8));
        $this->store = new StateStore($this->root);
        $runner = new class extends Runner {
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
                $output = '{}';
                if (in_array('info', $arguments, true)) {
                    $output = '{"ID":"daemon"}';
                } elseif (in_array('network', $arguments, true) && in_array('inspect', $arguments, true)) {
                    $name = $arguments[array_key_last($arguments)];
                    $owner = 'gateway-' . substr($name, strlen('baander-development-gateway-'));
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
        };
        $this->old = [
            'revision' => 'old',
            'postgres_image' => 'recorded-postgres',
            'redis_image' => 'recorded-redis',
            'nginx_image' => 'nginx',
        ];
        $this->new = [
            'revision' => 'new',
            'postgres_image' => 'candidate-postgres',
            'redis_image' => 'candidate-redis',
            'nginx_image' => 'nginx',
            'app_image' => 'app',
        ];
        $this->instance = [
            'id' => 'persistent',
            'kind' => 'persistent',
            'repository' => '/disposable/remote',
            'project' => 'activation-test',
            'hostname' => 'baander.test',
            'compose_file' => '/disposable/compose.json',
            'docker' => ['executable' => 'fake', 'context' => 'owned', 'daemon_id' => 'daemon'],
            'phase' => 'ready',
            'running_revision' => 'old',
            'release' => $this->old,
            'readiness' => ['web_ready' => true],
            'initialization' => 'complete',
        ];
        $this->store->save('persistent', $this->instance);
        $this->store->setEnabled('persistent', true);
        $this->updater = new class ($this->store, $runner) extends Updater {
            public function head(array $instance): string
            {
                return 'new';
            }
        };
        $this->updater->builder = $this->createStub(Builder::class);
        $this->updater->builder->method('prepare')->willReturn($this->new);
        $this->updater->runtime = $this->createStub(Runtime::class);
        $this->updater->runtime->method('write')->willReturnCallback(static fn(array $instance, array $release): array => $instance);
        $this->updater->runtime->method('ready')->willReturn(['web_ready' => true]);
        $this->updater->runtime->method('running')->willReturn(true);
        $this->updater->workers = $this->createStub(Workers::class);
        $this->updater->workers->method('running')->willReturn(true);
        $this->updater->database = $this->createStub(Database::class);
        $this->updater->database->method('inspect')->willReturn(['fresh' => false, 'pending' => ['next']]);
        $this->updater->database->method('checkpoint')->willReturn(['target_revision' => 'new', 'verified' => false]);
        $this->updater->database->method('rehearse')->willReturn(['target_revision' => 'new', 'verified' => true]);
        $this->updater->gateway->configure($this->instance, $this->new);
        mkdir($this->updater->gateway->directory . "/tls");
        file_put_contents($this->updater->gateway->directory . "/tls/cert.pem", "fixture");
        file_put_contents($this->updater->gateway->directory . "/tls/key.pem", "fixture");
    }

    protected function tearDown(): void
    {
        new Runner()->run(['rm', '-rf', '--', $this->root]);
    }

    public function testExistingDatabaseStartupReconcilesPersistedLibrariesWithoutInitialization(): void
    {
        $this->instance['libraries'] = [[
            'slug' => 'music', 'name' => 'Music', 'type' => 'music',
            'source' => '/host/music', 'target' => '/media/music',
        ]];
        $this->store->save('persistent', $this->instance);
        $database = $this->createMock(Database::class);
        $database->method('inspect')->willReturn(['fresh' => false, 'pending' => []]);
        $database->expects(self::never())->method('migrate');
        $this->updater->database = $database;
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::exactly(2))->method('run')->willReturnCallback(static function (array $arguments): ProcessResult {
            if (in_array('app:library:list', $arguments, true)) {
                return new ProcessResult(0, null, false, false, '[]', '', 0);
            }
            self::assertSame([
                'app:library:create', '--slug', 'music', '--json', '--', 'Music', '/media/music', 'music',
            ], array_slice($arguments, -8));
            return new ProcessResult(0, null, false, false, '{}', '', 0);
        });
        $this->updater->libraries = new Libraries($runner);
        $this->updater->update('persistent');
        $saved = $this->store->load('persistent');
        self::assertSame('ready', $saved['phase']);
        self::assertSame('new', $saved['running_revision']);
        self::assertTrue($saved['readiness']['web_ready']);
        self::assertEquals($this->instance['libraries'], $saved['libraries']);
    }

    public function testLibraryFailureAfterRuntimeReadinessNeverSavesReady(): void
    {
        $this->instance['libraries'] = [[
            'slug' => 'music', 'name' => 'Music', 'type' => 'music',
            'source' => '/host/music', 'target' => '/media/music',
        ]];
        $runtimeReady = false;
        $runtime = $this->createStub(Runtime::class);
        $runtime->method('ready')->willReturnCallback(static function () use (&$runtimeReady): array {
            $runtimeReady = true;
            return ['web_ready' => true];
        });
        $this->updater->runtime = $runtime;
        $runner = $this->createMock(Runner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(static function (array $arguments) use (&$runtimeReady): ProcessResult {
            self::assertTrue($runtimeReady);
            self::assertContains('app:library:list', $arguments);
            throw new WorkflowError('library catalog unavailable');
        });
        $this->updater->libraries = new Libraries($runner);
        try {
            $this->updater->startRelease($this->instance, $this->new);
            self::fail('Expected library reconciliation failure');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('list', $error->getMessage());
            $saved = $this->store->load('persistent');
            self::assertSame('activating', $saved['phase']);
            self::assertNull($saved['readiness']);
            self::assertSame('old', $saved['running_revision']);
        }
    }

    public function testIncompatiblePreflightPreservesPriorRuntimeAndStorageImages(): void
    {
        $database = $this->createStub(Database::class);
        $database->method('inspect')->willThrowException(new WorkflowError('incompatible history'));
        $this->updater->database = $database;
        $instance = array_replace($this->instance, ['postgres_image' => 'adopted-postgres', 'redis_image' => 'adopted-redis']);
        $this->store->save('persistent', $instance);
        try {
            $this->updater->update('persistent');
            self::fail('Expected preflight refusal');
        } catch (WorkflowError $error) {
            self::assertSame('incompatible history', $error->getMessage());
            $saved = $this->store->load('persistent');
            self::assertEquals(['ready', 'old', $this->old], [$saved['phase'], $saved['running_revision'], $saved['release']]);
            self::assertSame('adopted-postgres', $saved['prepared_release']['postgres_image']);
            self::assertSame('adopted-redis', $saved['prepared_release']['redis_image']);
            self::assertSame('new', $saved['failed_revision']);
        }
    }

    public function testCheckpointAndRehearsalFailureRestartOldWithoutMigration(): void
    {
        foreach (['checkpoint', 'rehearse'] as $boundary) {
            $this->store->save('persistent', $this->instance);
            $database = $this->createMock(Database::class);
            $database->method('inspect')->willReturn(['fresh' => false, 'pending' => ['next']]);
            $database->method('checkpoint')->willReturn(['target_revision' => 'new', 'verified' => false]);
            $database->method($boundary)->willThrowException(new WorkflowError('checkpoint unavailable'));
            $database->expects(self::never())->method('migrate');
            $this->updater->database = $database;
            try {
                $this->updater->update('persistent');
                self::fail('Expected checkpoint failure');
            } catch (WorkflowError) {
                $saved = $this->store->load('persistent');
                self::assertEquals(['ready', 'old', $this->old], [$saved['phase'], $saved['running_revision'], $saved['release']]);
                self::assertSame('new', $saved['failed_revision']);
            }
        }
    }

    public function testLostMigrationConfirmationBlocksAndPreservesCheckpoint(): void
    {
        $database = $this->createStub(Database::class);
        $database->method('inspect')->willReturn(['fresh' => false, 'pending' => ['next']]);
        $database->method('checkpoint')->willReturn(['target_revision' => 'new', 'verified' => false]);
        $database->method('rehearse')->willReturn(['target_revision' => 'new', 'verified' => true]);
        $database->method('migrate')->willReturnCallback(function (array $instance): array {
            $instance['phase'] = 'database_mutating';
            $instance['database_mutation_started'] = true;
            $this->store->save('persistent', $instance);
            throw new WorkflowError('later migration failed');
        });
        $this->updater->database = $database;
        try {
            $this->updater->update('persistent');
            self::fail('Expected migration failure');
        } catch (WorkflowError) {
            $saved = $this->store->load('persistent');
            self::assertSame('blocked', $saved['phase']);
            self::assertNull($saved['running_revision']);
            self::assertTrue($saved['checkpoint']['verified']);
            self::assertEquals($this->old, $saved['release']);
        }
    }

    public function testRecoveryRefusesUncertainPendingMigrationReplay(): void
    {
        $this->store->save(
            'persistent',
            array_replace(
                $this->instance,
                ['phase' => 'blocked', 'prepared_release' => $this->new, 'database_mutation_started' => true],
            ),
        );
        $this->expectExceptionMessage('reconciliation');
        $this->updater->recover('persistent');
    }

    public function testRecoveryRestoresDependenciesBeforeInspectionThenStartsConfirmedRelease(): void
    {
        $this->store->save(
            'persistent',
            array_replace(
                $this->instance,
                ['phase' => 'blocked', 'prepared_release' => $this->new, 'database_mutation_started' => true],
            ),
        );
        $dependencies = 0;
        $runtime = $this->createStub(Runtime::class);
        $runtime->method('write')->willReturnCallback(static fn(array $instance): array => $instance);
        $runtime->method('dependencies')->willReturnCallback(static function () use (&$dependencies): void {
            ++$dependencies;
        });
        $runtime->method('ready')->willReturn(['web_ready' => true]);
        $this->updater->runtime = $runtime;
        $database = $this->createStub(Database::class);
        $database->method('inspect')->willReturnCallback(static function () use (&$dependencies): array {
            self::assertSame(1, $dependencies);
            return ['fresh' => false, 'pending' => [], 'migration_hashes' => ['Version.php' => 'confirmed']];
        });
        $this->updater->database = $database;
        $this->updater->recover('persistent');
        $saved = $this->store->load('persistent');
        self::assertSame('ready', $saved['phase']);
        self::assertSame('new', $saved['running_revision']);
        self::assertSame(['Version.php' => 'confirmed'], $saved['migration_hashes']);
    }

    public function testDownRetainsInterruptedEvidenceAndBlocksFutureUpdate(): void
    {
        foreach (['blocked', 'database_mutating', 'checkpointing', 'quiescing', 'activating'] as $phase) {
            $checkpoint = ['directory' => '/checkpoint/recovery-source', 'verified' => true];
            $this->store->save(
                'persistent',
                array_replace($this->instance, ['phase' => $phase, 'checkpoint' => $checkpoint, 'prepared_release' => $this->new]),
            );
            $this->updater->down('persistent');
            $saved = $this->store->load('persistent');
            self::assertSame('blocked', $saved['phase']);
            self::assertSame($checkpoint, $saved['checkpoint']);
            self::assertEquals($this->new, $saved['prepared_release']);
            self::assertFalse($this->store->enabled('persistent'));
            $this->store->setEnabled('persistent', true);
            try {
                $this->updater->update('persistent');
                self::fail('Expected recovery requirement');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('explicit recovery', $error->getMessage());
            }
        }
    }
}
