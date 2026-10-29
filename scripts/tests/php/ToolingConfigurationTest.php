<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ToolingConfiguration;
use PHPUnit\Framework\TestCase;

final class ToolingConfigurationTest extends TestCase
{
    public function testAmbientDockerContextWinsOverHostAndWorkflowContextWinsOverAmbient(): void
    {
        $ambient = ['DOCKER_HOST' => 'unix:///tmp/baander.app-docker.sock', 'DOCKER_CONTEXT' => 'ambient'];
        $configuration = ToolingConfiguration::resolve(environment: $ambient);
        self::assertSame('ambient', $configuration->docker->context);
        self::assertNull($configuration->docker->host);
        $configuration = ToolingConfiguration::resolve(environment: $ambient + ['BAANDER_TEST_DOCKER_CONTEXT' => 'workflow']);
        self::assertSame('workflow', $configuration->docker->context);
        self::assertNull($configuration->docker->host);
        $configuration = ToolingConfiguration::resolve(environment: $ambient + ['BAANDER_TEST_DOCKER_CONTEXT' => '']);
        self::assertSame('ambient', $configuration->docker->context);
    }
    public function testHigherPrecedenceDockerSelectorReplacesOtherSelector(): void
    {
        $host = ToolingConfiguration::resolve(['docker' => ['host' => 'unix:///tmp/baander.app-docker.sock']], ['DOCKER_CONTEXT' => 'environment'], ['docker' => ['context' => 'local']]);
        self::assertSame('unix:///tmp/baander.app-docker.sock', $host->docker->host);
        self::assertNull($host->docker->context);
        self::assertSame('flags', $host->provenance['docker.context']);
        $context = ToolingConfiguration::resolve(['docker' => ['context' => 'flag']], ['DOCKER_HOST' => 'unix:///tmp/baander.app-docker.sock']);
        self::assertSame('flag', $context->docker->context);
        self::assertNull($context->docker->host);
    }

    public function testSamePrecedenceDockerSelectorsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(['docker' => ['host' => 'unix:///tmp/docker.sock', 'context' => 'context']]);
    }

    public function testInvalidLowerPrecedenceBudgetCannotBeHiddenByFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(['budgets' => ['backend' => 10]], local: ['budgets' => ['backend' => -1]]);
    }

    public function testResolvedSettingsHaveNonSecretSerialization(): void
    {
        $configuration = ToolingConfiguration::resolve();
        self::assertSame('docker', $configuration->toArray()['docker']['executable']);
        self::assertSame(120.0, $configuration->toArray()['budgets']['cleanup']);
        self::assertSame('defaults', $configuration->toArray()['provenance']['images.browser.reference']);
        self::assertArrayNotHasKey('libraries', $configuration->toArray());
    }
    public function testLeafPrecedenceAndEmptyOptionalEnvironment(): void
    {
        $local = ['budgets' => ['backend' => 50], 'docker' => ['executable' => '/local/docker']];
        $configuration = ToolingConfiguration::resolve(['budgets' => ['backend' => 70]], ['BAANDER_TEST_TIMEOUT' => '60', 'DOCKER_HOST' => ''], $local);
        self::assertSame(70.0, $configuration->budgets->seconds('backend'));
        self::assertSame('/local/docker', $configuration->docker->executable);
        self::assertNull($configuration->docker->host);
        self::assertSame('flags', $configuration->provenance['budgets.backend']);
        self::assertSame(60.0, ToolingConfiguration::resolve(environment: ['BAANDER_TEST_TIMEOUT' => '60'], local: $local)->budgets->seconds('backend'));
        self::assertSame(50.0, ToolingConfiguration::resolve(local: $local)->budgets->seconds('backend'));
        self::assertSame(3600.0, ToolingConfiguration::resolve()->budgets->seconds('backend'));
    }

    public function testEmptyCheckpointDoesNotCompeteWithSelectedCheckpoint(): void
    {
        $configuration = ToolingConfiguration::resolve(environment: ['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => '', 'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => '/tmp/baander.app-checkpoint']);
        self::assertNotNull($configuration->checkpoint);
        self::assertSame('autoscaling', $configuration->checkpoint->name);
        self::assertSame('/tmp/baander.app-checkpoint', $configuration->checkpoint->path);
    }

    public function testCompetingCheckpointsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(environment: ['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => '/tmp/one', 'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => '/tmp/two']);
    }

    public function testInvalidOverridesAreRejected(): void
    {
        foreach ([['unknown' => true], ['root' => '/opt/app'], ['budgets' => ['backend' => 0]], ['budgets' => ['backend' => INF]], ['budgets' => ['backend' => '60']], ['docker' => ['executable' => '']], ['images' => ['unknown' => ['reference' => 'redis:7']]]] as $local) {
            try {
                ToolingConfiguration::resolve(local: $local);
                self::fail('Accepted invalid override');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testMalformedNonemptyEnvironmentBudgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(environment: ['BAANDER_TEST_TIMEOUT' => 'forever']);
    }

    public function testExplicitEmptyFlagIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(['docker' => ['host' => '']]);
    }
}
