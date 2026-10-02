<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DockerDeploymentCreate;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real committed PostgreSQL admission; controlled Docker frames do not prove actual containment. */
final class DockerDeploymentCreateTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    /** @var array<string, mixed> */
    private array $params;
    /** @var list<Connection> */
    private array $extras = [];
    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $this->params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($this->params);
        $this->second = DriverManager::getConnection($this->params);
        $this->schema = 'docker_create_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002220000.php';
        $migration = new \DoctrineMigrations\Version20261002220000($this->first, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    public function testCreateCommitsIntentBeforeDockerAndBindingBeforeReturning(): void
    {
        $recipe = $this->recipe();
        $creator = $this->creator($recipe, onCreate: function (array $arguments) use ($recipe): string {
            self::assertSame($recipe->createArguments(), $arguments);
            self::assertSame(0, $this->first->getTransactionNestingLevel());
            self::assertTrue((new DoctrineDeploymentInventory($this->second))->matchesCreate($recipe), 'Independent observer sees committed intent before the external create.');
            return $this->containerId() . "\n";
        });
        $binding = $creator->create($recipe);
        self::assertEquals($this->binding(), $binding);
        self::assertSame(0, $this->first->getTransactionNestingLevel());
        self::assertEquals($binding, (new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
        self::assertNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers'));
        self::assertSame(1, $this->createCount());
        self::assertSame(0, $this->startCount());
    }

    #[DataProvider('uncertainReplies')]
    public function testLostOrMalformedCreateReplyNeverAuthorizesAnotherCreate(bool $throw): void
    {
        $recipe = $this->recipe();
        $failure = new \RuntimeException('Fixture create acknowledgment lost.');
        $creator = $this->creator($recipe, onCreate: static function () use ($throw, $failure): string {
            if ($throw) {
                throw $failure;
            }
            return 'baander.app-invalid-create-id';
        });
        try {
            $creator->create($recipe);
            self::fail('An uncertain create must not return a binding.');
        } catch (\RuntimeException $caught) {
            if ($throw) {
                self::assertSame($failure, $caught);
            }
        }
        self::assertTrue((new DoctrineDeploymentInventory($this->second))->matchesCreate($recipe));
        self::assertNull((new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
        try {
            $this->creator($recipe)->create($recipe);
            self::fail('A fresh controller cannot repeat a consumed create intent.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->createCount());
        }
        self::assertEquals($this->binding(), $this->creator($recipe)->reconcile($recipe));
        self::assertSame(1, $this->createCount());
        self::assertSame(0, $this->startCount());
    }

    /** @return iterable<string, array{bool}> */
    public static function uncertainReplies(): iterable
    {
        yield 'exception' => [true];
        yield 'malformed reply' => [false];
    }

    public function testExistingBootRefusesCreateWithoutDockerMutation(): void
    {
        $recipe = $this->recipe();
        self::assertTrue((new DoctrineDeploymentInventory($this->first))->register($this->binding()));
        try {
            $this->creator($recipe)->create($recipe);
            self::fail('An already bound boot must never create another container.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->createCount());
            self::assertSame(0, $this->startCount());
        }
    }

    public function testReconcileRequiresDurableMatchingIntentAndOnlyInspectsNameThenFullId(): void
    {
        $recipe = $this->recipe();
        $inventory = new DoctrineDeploymentInventory($this->first);
        try {
            $this->creator($recipe)->reconcile($recipe);
            self::fail('An unclaimed recipe cannot adopt a container.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->commands);
        }
        self::assertTrue($inventory->claimCreate($recipe));
        self::assertEquals($this->binding(), $this->creator($recipe)->reconcile($recipe));
        $targets = array_map(static fn (array $command): string => $command[array_key_last($command)], array_values(array_filter($this->commands, static fn (array $command): bool => $command[0] === 'container' && $command[1] === 'inspect')));
        self::assertSame([RegisteredDeploymentStart::containerName($recipe->namespace, $recipe->bootId), $this->containerId()], $targets);
        self::assertSame(0, $this->createCount());
        self::assertSame(0, $this->startCount());
    }

    #[DataProvider('mismatchedInspections')]
    public function testRecipeOrIdentityMismatchNeverRegisters(array $state, array $isolation): void
    {
        $recipe = $this->recipe();
        try {
            $this->creator($recipe, state: $state, isolation: $isolation)->create($recipe);
            self::fail('A mismatched actual container must not be admitted.');
        } catch (\RuntimeException) {
            self::assertNull((new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
            self::assertSame(1, $this->createCount());
            self::assertSame(0, $this->startCount());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function mismatchedInspections(): iterable
    {
        yield 'different immutable id' => [['id' => str_repeat('d', 64)], []];
        yield 'wrong name' => [['name' => '/baander.app-other-container'], []];
        yield 'wrong image' => [['image' => 'sha256:' . str_repeat('e', 64)], []];
        yield 'wrong entrypoint' => [['entrypoint' => ['/bin/sh']], []];
        yield 'wrong command' => [['cmd' => ['/app/bin/other.php']], []];
        yield 'wrong network' => [['network' => 'host'], []];
        yield 'unexpected attachment' => [['networks' => ['none' => [], 'baander.app-extra' => []]], []];
        yield 'wrong memory' => [['memory' => 1], []];
        yield 'unbounded swap' => [['memorySwap' => -1], []];
        yield 'wrong CPU' => [['nanoCpus' => 0], []];
        yield 'wrong PID cap' => [['pidsLimit' => 0], []];
        yield 'already exited' => [['status' => 'exited'], []];
        yield 'duplicate identity environment' => [['env' => ['BAANDER_WORKER_NAMESPACE=baander.app:create', 'BAANDER_WORKER_NAMESPACE=baander.app:create', 'BAANDER_WORKER_BOOT_ID=' . str_repeat('a', 32)]], []];
        yield 'wrong boot label' => [[], ['bootId' => str_repeat('b', 32)]];
        yield 'wrong deployment role' => [[], ['role' => 'child']];
        yield 'mounted socket' => [[], ['mounts' => [['Destination' => '/var/run/docker.sock']]]];
    }

    public function testWrongDaemonNeverCreates(): void
    {
        $recipe = $this->recipe();
        try {
            $this->creator($recipe, wrongDaemon: true)->create($recipe);
            self::fail('Daemon mismatch must fail before external mutation.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->createCount());
            self::assertNull((new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
        }
    }

    public function testReconcileCannotRebindPreviouslyRegisteredContainer(): void
    {
        $recipe = $this->recipe();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->claimCreate($recipe));
        self::assertTrue($inventory->register($this->binding()));
        try {
            $this->creator($recipe, state: ['id' => str_repeat('d', 64)])->reconcile($recipe);
            self::fail('Reconciliation must never overwrite the boot binding.');
        } catch (\RuntimeException) {
            self::assertEquals($this->binding(), (new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
            self::assertSame(0, $this->createCount());
        }
    }

    public function testRegistrationCommitAcknowledgmentLossRecoversWithoutRecreation(): void
    {
        $params = $this->params;
        $params['wrapperClass'] = DockerCreateCommitThenThrowConnection::class;
        $uncertain = DriverManager::getConnection($params);
        self::assertInstanceOf(DockerCreateCommitThenThrowConnection::class, $uncertain);
        $this->extras[] = $uncertain;
        $uncertain->executeStatement('SET search_path TO ' . $this->schema);
        $uncertain->throwOnCommit = 3; // find, durable create intent, then registration.
        $recipe = $this->recipe();
        $creator = new DockerDeploymentCreate(new DoctrineDeploymentInventory($uncertain), $this->executor($recipe));
        try {
            $creator->create($recipe);
            self::fail('A lost registration acknowledgment cannot return an admitted binding.');
        } catch (\RuntimeException $caught) {
            self::assertSame('Fixture registration commit acknowledgment lost.', $caught->getMessage());
        }
        self::assertFalse($uncertain->isConnected());
        self::assertEquals($this->binding(), (new DoctrineDeploymentInventory($this->second))->find($recipe->namespace, $recipe->bootId));
        self::assertEquals($this->binding(), $this->creator($recipe)->reconcile($recipe));
        self::assertSame(1, $this->createCount());
        self::assertSame(0, $this->startCount());
    }

    private function recipe(): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('baander.app:create', str_repeat('a', 32), 'baander.app-daemon', 'sha256:' . str_repeat('f', 64), ['/usr/local/bin/php', '/app/bin/worker.php'], 'none', 67108864, 1000000000, 32);
    }

    private function containerId(): string
    {
        return str_repeat('c', 64);
    }

    private function binding(): DeploymentContainer
    {
        $recipe = $this->recipe();
        return new DeploymentContainer($recipe->namespace, $recipe->bootId, $recipe->daemonId, $this->containerId());
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $isolation */
    private function creator(DeploymentContainerRecipe $recipe, ?\Closure $onCreate = null, array $state = [], array $isolation = [], bool $wrongDaemon = false): DockerDeploymentCreate
    {
        return new DockerDeploymentCreate(new DoctrineDeploymentInventory($this->first), $this->executor($recipe, $onCreate, $state, $isolation, $wrongDaemon));
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $isolation @return \Closure(list<string>): string */
    private function executor(DeploymentContainerRecipe $recipe, ?\Closure $onCreate = null, array $state = [], array $isolation = [], bool $wrongDaemon = false): \Closure
    {
        return function (array $arguments) use ($recipe, $onCreate, $state, $isolation, $wrongDaemon): string {
            $this->commands[] = $arguments;
            if ($arguments[0] === 'info') {
                return $wrongDaemon ? 'baander.app-wrong-daemon' : $recipe->daemonId;
            }
            if ($arguments[1] === 'create') {
                self::assertSame($recipe->createArguments(), $arguments);
                return $onCreate !== null ? $onCreate($arguments) : $this->containerId();
            }
            self::assertSame('inspect', $arguments[1], 'Creation and reconciliation must never start/remove a container.');
            if (str_contains($arguments[3], '"entrypoint"')) {
                return json_encode(array_replace([
                    'id' => $this->containerId(), 'name' => '/' . RegisteredDeploymentStart::containerName($recipe->namespace, $recipe->bootId),
                    'image' => $recipe->imageId, 'entrypoint' => [$recipe->command[0]], 'cmd' => array_slice($recipe->command, 1),
                    'env' => ['PATH=/usr/local/bin:/usr/bin', 'BAANDER_WORKER_NAMESPACE=' . $recipe->namespace, 'BAANDER_WORKER_BOOT_ID=' . $recipe->bootId],
                    'network' => $recipe->network, 'networks' => [$recipe->network => []], 'memory' => $recipe->memoryBytes, 'memorySwap' => $recipe->memoryBytes,
                    'nanoCpus' => $recipe->nanoCpus, 'pidsLimit' => $recipe->pidsLimit,
                    'status' => 'created', 'running' => false, 'pid' => 0, 'restarting' => false, 'restart' => 'no', 'retryCount' => 0,
                ], $state), JSON_THROW_ON_ERROR);
            }
            return json_encode(array_replace([
                'id' => $state['id'] ?? $this->containerId(), 'namespace' => $recipe->namespace, 'bootId' => $recipe->bootId, 'role' => 'deployment',
                'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private', 'mounts' => [], 'binds' => null, 'tmpfs' => null,
                'devices' => null, 'deviceRequests' => null, 'capAdd' => null, 'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges:true'],
            ], $isolation), JSON_THROW_ON_ERROR);
        };
    }

    private function createCount(): int
    {
        return count(array_filter($this->commands, static fn (array $command): bool => $command[0] === 'container' && $command[1] === 'create'));
    }

    private function startCount(): int
    {
        return count(array_filter($this->commands, static fn (array $command): bool => $command[0] === 'container' && $command[1] === 'start'));
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->second->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null, ...$this->extras] as $connection) {
            $connection?->close();
        }
    }
}

/** Actual registration commit succeeds; only its acknowledgment is lost. */
final class DockerCreateCommitThenThrowConnection extends Connection
{
    public int $throwOnCommit = 0;
    private int $commits = 0;

    public function commit(): void
    {
        parent::commit();
        if (++$this->commits === $this->throwOnCommit) {
            throw new \RuntimeException('Fixture registration commit acknowledgment lost.');
        }
    }
}
