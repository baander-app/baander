<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ToolingConfiguration;
use PHPUnit\Framework\TestCase;

final class InvocationConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander.app-invocation-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/dev.local.json');
        rmdir($this->root);
    }

    public function testLocalToolingUsesPrecedenceWithoutValidatingMediaDirectories(): void
    {
        file_put_contents($this->root . '/dev.local.json', json_encode([
            'libraries' => [['path' => '/missing/baander.app/media']],
            'tooling' => ['budgets' => ['messaging' => 17], 'images' => ['application' => ['reference' => 'local.baander.app/application:qualification']]],
        ], JSON_THROW_ON_ERROR));
        $configuration = ToolingConfiguration::forRepository($this->root, ['BAANDER_TEST_IMAGE' => 'env.baander.app/application:qualification']);
        self::assertSame(17.0, $configuration->budgets->seconds('messaging'));
        self::assertSame('env.baander.app/application:qualification', $configuration->images->role('application')->reference);
        $configuration = ToolingConfiguration::forRepository($this->root, ['BAANDER_TEST_IMAGE' => 'env.baander.app/application:qualification'], ['images' => ['application' => ['reference' => 'flag.baander.app/application:qualification']]]);
        self::assertSame('flag.baander.app/application:qualification', $configuration->images->role('application')->reference);
    }

    public function testCiIgnoresInvalidMachineLocalConfiguration(): void
    {
        file_put_contents($this->root . '/dev.local.json', 'invalid');
        self::assertSame(600.0, ToolingConfiguration::forRepository($this->root, ['CI' => 'true'])->budgets->seconds('messaging'));
    }

    public function testUnknownLocalSettingFailsAtTheInvocationBoundary(): void
    {
        file_put_contents($this->root . '/dev.local.json', '{"tooling":{"budgets":{"unknown":5}}}');
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::forRepository($this->root, []);
    }

    public function testMalformedLocalBudgetFailsAtTheInvocationBoundary(): void
    {
        file_put_contents($this->root . '/dev.local.json', '{"tooling":{"budgets":{"backend":"90"}}}');
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::forRepository($this->root, []);
    }
}
