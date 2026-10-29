<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryCleanupTest extends TestCase
{
    public function testRelayQualificationContextLoadsWithoutTheRootPackage(): void
    {
        $relay = dirname(__DIR__, 3) . '/relay';
        $ignore = (string) file_get_contents($relay . '/.dockerignore');
        self::assertStringContainsString("\n!tools/qualification/\n!tools/qualification/**\n", $ignore);
        $dockerfile = (string) file_get_contents($relay . '/docker/qualification.Dockerfile');
        self::assertStringContainsString('COPY tools/qualification /qualification/tools/qualification', $dockerfile);
        self::assertStringNotContainsString('packages/baander-tooling', $dockerfile);
        $root = sys_get_temp_dir() . '/baander-relay-context-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            self::copyDirectory($relay . '/vendor', $root . '/vendor');
            self::copyDirectory($relay . '/tools/qualification', $root . '/tools/qualification');
            $source = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
foreach ([Baander\Registry\Qualification\BoundedStorage::class, Baander\Registry\Qualification\DockerOwnership::class] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Missing standalone relay qualification class: ' . $class);
    }
}
if (class_exists('Baander\\Tooling\\Registry\\QualificationRunner')) {
    throw new RuntimeException('Relay context unexpectedly depends on the root package.');
}
echo "relay-only qualification autoload passed\n";
PHP;
            $result = new ProcessRunner()->run(new ProcessSpec([PHP_BINARY, '-r', $source, $root], environment: getenv(), timeout: 10, processGroup: true));
            self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
            self::assertStringContainsString('relay-only qualification autoload passed', $result->stdout);
        } finally {
            \Baander\Tooling\Inventory\Collector::remove($root);
        }
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0700, true);
        }
        foreach (new \FilesystemIterator($source) as $entry) {
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                self::copyDirectory($entry->getPathname(), $target);
            } else {
                copy($entry->getPathname(), $target);
            }
        }
    }

    /** @return iterable<string,array{int,int}> */
    public static function nestedOutcomes(): iterable
    {
        yield 'primary gate failed' => [23, 23];
        yield 'gate otherwise passed' => [0, 1];
        yield 'gate interrupted' => [143, 143];
    }

    #[DataProvider('nestedOutcomes')]
    public function testNestedDockerCleanupPreservesPrimaryFailureAndRetainsTargetedRetry(int $primary, int $expected): void
    {
        $root = sys_get_temp_dir() . '/baander-registry-nested-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $environment = array_replace(getenv(), ['REGISTRY_TEST_STATE' => $root, 'STUB_FAIL' => 'cleanup', 'REGISTRY_NESTED_RECEIPT' => $root . '/nested-resources.json']);
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                [PHP_BINARY, __DIR__ . '/Fixtures/registry-nested-ownership.php', $root, (string) $primary],
                environment: $environment,
                timeout: 20,
                processGroup: true,
            ));
            self::assertSame($expected, $result->exitCode, $result->stdout . $result->stderr);
            if ($primary === 23) {
                self::assertStringContainsString('primary gate failed', $result->stderr);
            }
            if ($primary === 143) {
                self::assertStringContainsString('interrupted by signal 15', $result->stderr);
            }
            self::assertStringContainsString('Retry receipt:', $result->stderr);
            $receipt = $root . '/nested-resources.json';
            self::assertFileExists($receipt);
            $state = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('registry-fixture-daemon', $state['target']['daemonId']);
            self::assertCount(1, $state['resources']);
            self::assertSame('container', $state['resources'][0]['kind']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state['resources'][0]['id']);
            $calls = (string) file_get_contents($root . '/calls');
            self::assertStringContainsString('volume rm', $calls);
            self::assertStringContainsString('network rm', $calls);
            $retry = new ProcessRunner()->run(new ProcessSpec(
                [PHP_BINARY, dirname(__DIR__, 3) . '/relay/tools/qualification/cleanup.php', '--receipt', $receipt],
                environment: array_replace($environment, ['STUB_FAIL' => '']),
                timeout: 20,
                processGroup: true,
            ));
            self::assertSame(0, $retry->exitCode, $retry->stdout . $retry->stderr);
            self::assertFileDoesNotExist($receipt);
        } finally {
            \Baander\Tooling\Inventory\Collector::remove($root);
        }
    }

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
