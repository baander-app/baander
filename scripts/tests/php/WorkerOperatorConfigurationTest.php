<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Target;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Fixtures\Worker\{OperatorCommands, OperatorConfiguration, OperatorTopology};
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerOperatorConfigurationTest extends TestCase
{
    /** @return iterable<string,array{array<string,string>,float}> */
    public static function operatorBudgets(): iterable
    {
        yield 'standalone' => [[], 240.0];
        yield 'paired deployment' => [['BAANDER_TEST_TWO_COMMAND' => '1'], 600.0];
        yield 'Redis lifecycle' => [['BAANDER_TEST_WORKER_LIFECYCLE_PHASE' => 'redis'], 1300.0];
        yield 'PostgreSQL lifecycle' => [['BAANDER_TEST_WORKER_LIFECYCLE_PHASE' => 'postgres'], 1300.0];
        yield 'capacity checkpoint' => [['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => '/fixture/capacity'], 600.0];
        yield 'autoscaling checkpoint' => [['BAANDER_TEST_AUTOSCALING_CHECKPOINT' => '/fixture/autoscaling'], 600.0];
        yield 'media observation' => [['BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '1'], 600.0];
    }

    /** @param array<string,string> $environment */
    #[DataProvider('operatorBudgets')]
    public function testOperatorUsesTheSelectedScenarioBudget(array $environment, float $seconds): void
    {
        $started = Runtime::now();
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment);
        try {
            $deadline = $commands->runtime->executor->context->deadline;
            self::assertGreaterThanOrEqual($started + $seconds, $deadline);
            self::assertLessThanOrEqual(Runtime::now() + $seconds, $deadline);
        } finally {
            $commands->close();
        }
    }

    public function testScenarioBudgetDoesNotExtendTheOwningDeadline(): void
    {
        $runtime = new Runtime(dirname(__DIR__, 3), 10, []);
        try {
            $command = $runtime->executor->context->command(new ProcessSpec(['php', 'operator.php'], timeout: 600));
            self::assertGreaterThan(0, $command->timeout);
            self::assertLessThanOrEqual(10, $command->timeout);
        } finally {
            $runtime->close();
        }
    }

    public function testAutoscalingAggregateHasASeparateBudgetFromEachProfile(): void
    {
        $configuration = ToolingConfiguration::resolve();
        self::assertSame(600.0, $configuration->budgets->seconds('workerCapacity'));
        self::assertSame(2000.0, $configuration->budgets->seconds('workerAutoscaling'));
    }

    public function testMediaObservationBuildsAFixtureByDefault(): void
    {
        $configuration = new OperatorConfiguration(['BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '1']);
        self::assertTrue($configuration->mediaObservation);
        self::assertFalse($configuration->useImage);
    }

    public function testEmptyCheckpointDoesNotHideTheSelectedAutoscalingCheckpoint(): void
    {
        $configuration = new OperatorConfiguration([
            'BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => '',
            'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => '/fixture/autoscaling',
        ]);
        self::assertSame('autoscaling', $configuration->checkpoint->name);
        self::assertSame('/fixture/autoscaling', $configuration->checkpoint->path);
    }

    public function testConflictingCheckpointsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperatorConfiguration([
            'BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => '/fixture/capacity',
            'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => '/fixture/autoscaling',
        ]);
    }

    public function testContainerTransportRequiresAnExplicitSocketMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperatorTopology(new Target('/fixture/docker', 'unix:///selected.sock', 'fixture-daemon', null, null), true, []);
    }

    public function testMappedOperatorRetainsTheSelectedDaemonIdentity(): void
    {
        $topology = new OperatorTopology(new Target('/fixture/docker', 'unix:///selected.sock', 'fixture-daemon', null, null), true, [
            'BAANDER_TEST_DOCKER_SOCKET_PATH' => '/daemon/docker.sock',
        ]);
        self::assertSame('/daemon/docker.sock', $topology->operatorTransport->daemonHostSocketPath);
        self::assertSame('unix:///run/baander-tooling/docker.sock', $topology->operatorTarget()->endpoint);
        self::assertSame('fixture-daemon', $topology->operatorTarget()->daemonId);
    }
}
