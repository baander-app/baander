<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Runs the real shard runner in a child process against fixture tests outside every phpunit.xml.dist suite. */
final class PhpunitShardRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-phpunit-shard-runner-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPassingTestsExitZeroWithoutFailedShards(): void
    {
        $process = $this->runShards(['<file>' . self::fixture('PassingShardFixtureTest.php') . '</file>']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('for 1 discovered tests; 0 failed shards.', $process->getOutput());
    }

    public function testFailingTestExitsNonzeroAndCountsFailedShard(): void
    {
        $process = $this->runShards([
            '<file>' . self::fixture('PassingShardFixtureTest.php') . '</file>',
            '<file>' . self::fixture('FailingShardFixtureTest.php') . '</file>',
        ]);

        self::assertSame(1, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('for 2 discovered tests; 1 failed shards.', $process->getOutput());
        self::assertStringContainsString('Deliberate shard failure.', $process->getOutput());
    }

    public function testConfigurationWithoutTestsExitsNonzero(): void
    {
        $empty = $this->directory . '/empty';
        (new Filesystem())->mkdir($empty);

        $process = $this->runShards(['<directory>' . $empty . '</directory>']);

        self::assertNotSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('failed shards.', $process->getOutput());
    }

    /** @param list<string> $entries */
    private function runShards(array $entries): Process
    {
        // The runner changes to the repository root, so the configuration path must be absolute.
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, sprintf(
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit failOnRisky="true" failOnWarning="true">
    <testsuites>
        <testsuite name="ShardFixtures">
            %s
        </testsuite>
    </testsuites>
</phpunit>
XML,
            implode("\n            ", $entries),
        ));

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 5) . '/scripts/run-phpunit-shards.php',
            '-c',
            $configuration,
            '--no-progress',
            '--colors=never',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    private static function fixture(string $file): string
    {
        return dirname(__DIR__, 5) . '/tests/Fixtures/PhpunitShards/' . $file;
    }
}
