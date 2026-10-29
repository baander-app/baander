<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\RepositoryConfiguration;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryConfigurationTest extends TestCase
{
    private string $directory;
    private EngineExecutor $executor;
    /** @var array<string,string> */
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-source-configuration-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/archive', 0700, true);
        $this->environment = array_replace(getenv(), ['PATH' => $this->directory, 'CI' => '0']);
        $this->executor = new EngineExecutor(ExecutionContext::bounded(5, $this->environment));
    }

    protected function tearDown(): void
    {
        $this->executor->close();
        Collector::remove($this->directory);
    }

    public function testSourceArchiveWithoutGitUsesNoLocalConfigurationOrParentFallback(): void
    {
        file_put_contents($this->directory . '/dev.local.json', '{"tooling":{"budgets":{"backend":90}}}');
        self::assertNull(RepositoryConfiguration::discover($this->executor, $this->directory . '/archive'));
        self::assertSame([], RepositoryConfiguration::local($this->executor, $this->directory . '/archive', $this->environment));
    }

    public function testSourceArchiveLoadsItsExplicitLocalConfigurationWithoutGit(): void
    {
        file_put_contents($this->directory . '/archive/dev.local.json', '{"tooling":{"budgets":{"backend":90}}}');
        self::assertSame(
            ['tooling' => ['budgets' => ['backend' => 90]]],
            RepositoryConfiguration::local($this->executor, $this->directory . '/archive', $this->environment),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function malformedConfiguration(): iterable
    {
        yield 'invalid JSON' => ['{broken'];
        yield 'nonobject root' => ['[]'];
        yield 'nonobject tooling' => ['{"tooling":[]}'];
    }

    #[DataProvider('malformedConfiguration')]
    public function testSourceArchiveDoesNotIgnoreMalformedLocalConfiguration(string $json): void
    {
        file_put_contents($this->directory . '/archive/dev.local.json', $json);
        $this->expectException(\InvalidArgumentException::class);
        RepositoryConfiguration::local($this->executor, $this->directory . '/archive', $this->environment);
    }
}
