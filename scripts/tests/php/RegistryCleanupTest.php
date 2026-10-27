<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryCleanupTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function contracts(): iterable
    {
        yield 'cluster resources' => ['test_cluster_cleanup.php', 'exhaustive cleanup, reaping and literal argv'];
        yield 'bounded storage' => ['test_storage_cleanup.php', 'diagnostic controls and failure-preserving cleanup'];
    }

    #[DataProvider('contracts')]
    public function testStandaloneCleanupContracts(string $driver, string $milestone): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            [PHP_BINARY, dirname(__DIR__, 3) . '/relay/tests/' . $driver],
            environment: getenv(),
            timeout: 45,
            processGroup: true,
            terminationGrace: 5,
        ));
        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        self::assertStringContainsString($milestone, $result->stdout);
    }
}
