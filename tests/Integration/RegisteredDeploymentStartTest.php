<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\DTO\DeploymentUnit;

use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real committed start admission; controlled Docker frames supply no actual process-containment proof. */
final class RegisteredDeploymentStartTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;
    private int $startCalls = 0;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        $this->schema = 'registered_start_test_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        \App\Tests\Support\FinalSchemaBootstrap::bootstrap($this->first);
    }

    public function testBindingAndOneShotClaimAreCommittedBeforeDockerStart(): void
    {
        $binding = $this->binding();
        $controller = $this->starter(new DoctrineDeploymentInventory($this->first), $this->executor($binding, function (array $arguments) use ($binding): string {
            self::assertSame(['container', 'start', $binding->containerId], $arguments);
            self::assertSame(0, $this->first->getTransactionNestingLevel());
            $row = $this->second->fetchAssociative('SELECT namespace, boot_id, daemon_id, container_id, start_claimed_at FROM worker_deployment_containers WHERE unit = \'control\'');
            self::assertIsArray($row);
            self::assertSame($binding->namespace, $row['namespace']);
            self::assertSame($binding->bootId, $row['boot_id']);
            self::assertSame($binding->daemonId, $row['daemon_id']);
            self::assertSame($binding->containerId, $row['container_id']);
            self::assertNotNull($row['start_claimed_at'], 'Independent connection observes the committed claim at the instant of start.');
            return $binding->containerId . "\n";
        }));
        self::assertTrue($controller->start($binding));
        self::assertSame(1, $this->startCalls);
        self::assertFalse($controller->start($binding), 'Even an apparently still-created container cannot repeat its consumed claim.');
        self::assertSame(1, $this->startCalls);
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('unsafePrecreatedStates')]
    public function testUnsafeContainerNeverStartsOrClaims(array $state, bool $wrongDaemon): void
    {
        $binding = $this->binding();
        $controller = $this->starter(new DoctrineDeploymentInventory($this->first), $this->executor($binding, states: [$state], wrongDaemon: $wrongDaemon));
        try {
            $controller->start($binding);
            self::fail('An unsafe startup target must be rejected.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->startCalls);
            self::assertFalse($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers WHERE unit = \'control\''), 'Inspection rejection happens before registration or start admission.');
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function unsafePrecreatedStates(): iterable
    {
        yield 'wrong name' => [['name' => '/baander.app-wrong-worker'], false];
        yield 'wrong daemon' => [[], true];
        yield 'running' => [['status' => 'running', 'running' => true, 'pid' => 123], false];
        yield 'exited' => [['status' => 'exited'], false];
        yield 'restart always' => [['restart' => 'always'], false];
        yield 'restarting' => [['restarting' => true], false];
        yield 'retry count' => [['retryCount' => 1], false];
        yield 'nonzero PID' => [['pid' => 123], false];
    }

    public function testImmutableRegistrationConflictNeverClaimsOrStartsDifferentContainer(): void
    {
        $old = $this->binding();
        $inventory = new DoctrineDeploymentInventory($this->first);
        self::assertTrue($inventory->register($old));
        $candidate = new DeploymentContainer($old->namespace, $old->bootId, $old->daemonId, str_repeat('d', 64), DeploymentUnit::Control);
        $controller = $this->starter($inventory, $this->executor($candidate));
        self::assertFalse($controller->start($candidate));
        self::assertSame(0, $this->startCalls);
        self::assertEquals($old, (new DoctrineDeploymentInventory($this->second))->findUnit($old->namespace, $old->bootId, $old->unit));
        self::assertNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers WHERE unit = \'control\''));
    }

    public function testConcurrentControllerCannotIssueSecondStartForCommittedClaim(): void
    {
        $binding = $this->binding();
        $stateChecks = 0;
        $contenderResult = null;
        $executor = $this->executor($binding, onState: function () use (&$stateChecks, &$contenderResult, $binding): void {
            if (++$stateChecks === 2) {
                $contender = $this->starter(new DoctrineDeploymentInventory($this->second), $this->executor($binding));
                $contenderResult = $contender->start($binding);
            }
        });
        $controller = $this->starter(new DoctrineDeploymentInventory($this->first), $executor);
        self::assertTrue($controller->start($binding));
        self::assertFalse($contenderResult, 'The second controller sees the first claim before the first start call.');
        self::assertSame(1, $this->startCalls);
    }

    #[DataProvider('uncertainStartResponses')]
    public function testFailedOrMalformedStartConsumesClaimAndNeverRepeatsStart(bool $throw): void
    {
        $binding = $this->binding();
        $failure = new \RuntimeException('Fixture Docker start acknowledgment is uncertain.');
        $controller = $this->starter(new DoctrineDeploymentInventory($this->first), $this->executor($binding, static function () use ($throw, $failure): string {
            if ($throw) {
                throw $failure;
            }
            return "baander.app-unconfirmed-container\n";
        }));
        try {
            $controller->start($binding);
            self::fail('An uncertain start must not return success.');
        } catch (\RuntimeException $caught) {
            if ($throw) {
                self::assertSame($failure, $caught);
            } else {
                self::assertStringContainsString('Docker start was not confirmed', $caught->getMessage());
            }
        }
        self::assertNotNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers WHERE unit = \'control\''));
        self::assertSame(1, $this->startCalls);
        self::assertFalse($controller->start($binding));
        self::assertSame(1, $this->startCalls, 'A lost start acknowledgment cannot cause a duplicate start.');
    }

    /** @return iterable<string, array{bool}> */
    public static function uncertainStartResponses(): iterable
    {
        yield 'transport exception' => [true];
        yield 'malformed acknowledgment' => [false];
    }

    public function testStateChangingAfterClaimPreventsStartAndKeepsClaimConsumed(): void
    {
        $binding = $this->binding();
        $controller = $this->starter(new DoctrineDeploymentInventory($this->first), $this->executor($binding, states: [[], ['status' => 'running', 'running' => true, 'pid' => 123]]));
        try {
            $controller->start($binding);
            self::fail('Post-claim state change must prevent the start command.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->startCalls);
        }
        self::assertNotNull($this->second->fetchOne('SELECT start_claimed_at FROM worker_deployment_containers WHERE unit = \'control\''));
        self::assertFalse((new DoctrineDeploymentInventory($this->second))->claimStart($binding), 'A failed post-claim inspection must never reset admission.');
    }

    /** @param \Closure(list<string>):string $execute */
    private function starter(DoctrineDeploymentInventory $inventory, \Closure $execute): RegisteredDeploymentStart
    {
        return new RegisteredDeploymentStart($inventory, $execute, $this->recipe(DeploymentUnit::Control));
    }

    private function recipe(DeploymentUnit $unit): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('baander.app:startup', str_repeat('a', 32), 'baander.app-daemon', 'sha256:' . str_repeat('f', 64),
            ['/usr/local/bin/php', '/var/www/html/bin/console', 'app:worker', '--unit=' . $unit->value],
            'none', 67108864, 1000000000, 32, $unit, $unit === DeploymentUnit::Control ? [0] : [1], 'worker-ipc', '/var/www/html');
    }

    private function binding(): DeploymentContainer
    {
        $inventory = new DoctrineDeploymentInventory($this->first);
        if ($inventory->findBoot('baander.app:startup', str_repeat('a', 32)) === null) {
            self::assertTrue($inventory->reserveBoot('baander.app:startup', str_repeat('a', 32), str_repeat('f', 64), 'baander.app-daemon', $this->recipe(DeploymentUnit::Control)->fingerprint(), $this->recipe(DeploymentUnit::Execution)->fingerprint()));
            foreach (DeploymentUnit::cases() as $unit) {
                self::assertTrue($inventory->claimCreate('baander.app:startup', str_repeat('a', 32), $unit));
            }
            self::assertTrue($inventory->register(new DeploymentContainer('baander.app:startup', str_repeat('a', 32), 'baander.app-daemon', str_repeat('e', 64), DeploymentUnit::Execution)));
        }
        return new DeploymentContainer('baander.app:startup', str_repeat('a', 32), 'baander.app-daemon', str_repeat('c', 64), DeploymentUnit::Control);
    }

    /**
     * @param (\Closure(list<string>): string)|null $onStart
     * @param list<array<string, mixed>> $states Overrides for successive startup-state inspections; final override repeats.
     * @param (\Closure(): void)|null $onState
     * @return \Closure(list<string>): string
     */
    private function executor(DeploymentContainer $binding, ?\Closure $onStart = null, array $states = [], bool $wrongDaemon = false, ?\Closure $onState = null): \Closure
    {
        $stateChecks = 0;
        return function (array $arguments) use ($binding, $onStart, $states, $wrongDaemon, $onState, &$stateChecks): string {
            if ($arguments[0] === 'info') {
                return $wrongDaemon ? 'baander.app-other-daemon' : $binding->daemonId;
            }
            if ($arguments[0] === 'volume') {
                self::assertSame('worker-ipc', $arguments[4]);
                return json_encode(['name' => 'worker-ipc', 'driver' => 'local', 'options' => null, 'scope' => 'local', 'mountpoint' => '/var/lib/docker/volumes/worker-ipc/_data'], JSON_THROW_ON_ERROR);
            }
            self::assertSame($binding->containerId, $arguments[array_key_last($arguments)], 'Only the exact bound container is addressed.');
            if ($arguments[1] === 'start') {
                ++$this->startCalls;
                return $onStart === null ? $binding->containerId : $onStart($arguments);
            }
            self::assertSame('inspect', $arguments[1]);
            if (str_contains($arguments[3], '"status"')) {
                $onState?->__invoke();
                $override = $states === [] ? [] : $states[min($stateChecks, count($states) - 1)];
                ++$stateChecks;
                return json_encode(array_replace(['id' => $binding->containerId, 'name' => '/' . RegisteredDeploymentStart::containerName($binding->namespace, $binding->bootId, $binding->unit),
                    'status' => 'created', 'running' => false, 'pid' => 0, 'restart' => 'no', 'restarting' => false, 'retryCount' => 0], $override), JSON_THROW_ON_ERROR);
            }
            return json_encode(['id' => $binding->containerId, 'namespace' => $binding->namespace, 'bootId' => $binding->bootId, 'role' => $binding->unit->value,
                'memory' => 67108864, 'memorySwap' => 67108864, 'nanoCpus' => 1000000000, 'pidsLimit' => 32, 'cpuSet' => '0', 'restart' => 'no', 'retryCount' => 0,
                'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private', 'mounts' => [['Type' => 'volume', 'Name' => 'worker-ipc', 'Driver' => 'local', 'Source' => '/var/lib/docker/volumes/worker-ipc/_data', 'Destination' => '/run/baander-worker', 'RW' => true]], 'binds' => null, 'tmpfs' => null,
                'devices' => [], 'deviceRequests' => [], 'capAdd' => null, 'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges']], JSON_THROW_ON_ERROR);
        };
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null, $this->second ?? null] as $connection) {
            if ($connection !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        foreach ([$this->first ?? null, $this->second ?? null] as $connection) {
            $connection?->close();
        }
    }
}
