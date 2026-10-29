<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Target;
use Baander\Tooling\Fixtures\Worker\{OperatorConfiguration, OperatorTopology};
use PHPUnit\Framework\TestCase;

final class WorkerOperatorConfigurationTest extends TestCase
{
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
