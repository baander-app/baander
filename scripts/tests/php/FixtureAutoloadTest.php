<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class FixtureAutoloadTest extends TestCase
{
    public function testDisposableFixtureLoadsWorkloadClassesWithoutInstallingDevelopmentDependencies(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/baander-workload-fixture-loader-' . (random_bytes(8) |> bin2hex(...));
        foreach (['vendor/composer', 'scripts/tests/php', 'tests/Fixtures/Worker/Autoscaling', 'src/Shared/Application/Messaging'] as $path) {
            mkdir($directory . '/' . $path, 0700, true);
        }
        file_put_contents($directory . '/composer.json', json_encode([
            'name' => 'baander/workload-fixture-loader',
            'require' => ['php' => '>=8.5'],
            'require-dev' => ['phpunit/phpunit' => '^13.0'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/'], 'classmap' => ['scripts/tests/php/']],
        ], JSON_THROW_ON_ERROR));
        $metadata = json_encode(['packages' => [], 'dev' => false, 'dev-package-names' => []], JSON_THROW_ON_ERROR);
        file_put_contents($directory . '/vendor/composer/installed.json', $metadata);
        foreach (['Workload.php', 'WorkloadCodec.php', 'WorkloadHandler.php'] as $name) {
            copy($root . '/tests/Fixtures/Worker/Autoscaling/' . $name, $directory . '/tests/Fixtures/Worker/Autoscaling/' . $name);
        }
        copy($root . '/src/Shared/Application/Messaging/MessagePayloadCodecInterface.php', $directory . '/src/Shared/Application/Messaging/MessagePayloadCodecInterface.php');
        $environment = getenv();
        $environment['COMPOSER_DISABLE_NETWORK'] = '1';
        $executor = new EngineExecutor(ExecutionContext::bounded(30, $environment, $directory));
        $probe = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
echo json_encode([
    'workload' => class_exists(\App\Tests\Fixtures\Worker\Autoscaling\Workload::class),
    'codec' => class_exists(\App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec::class),
    'handler' => class_exists(\App\Tests\Fixtures\Worker\Autoscaling\WorkloadHandler::class),
    'phpunit' => class_exists(\PHPUnit\Framework\TestCase::class),
], JSON_THROW_ON_ERROR);
PHP;
        try {
            $executor->run(new ProcessSpec(['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-scripts', '--no-plugins']));
            $before = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $probe, $directory]));
            self::assertSame(['workload' => false, 'codec' => false, 'handler' => false, 'phpunit' => false], json_decode($before->stdout, true, flags: JSON_THROW_ON_ERROR));
            $dockerfile = new \ReflectionClass(\Baander\Tooling\Fixtures\Worker\OperatorProvisioning::class)->getConstant('FIXTURE_DOCKERFILE');
            $command = $dockerfile
                |> (static fn(string $source): array => explode("\n", $source))
                |> (static fn(array $lines): ?string => array_find($lines, static fn(string $line): bool => str_starts_with($line, 'RUN composer dump-autoload ')));
            self::assertNotNull($command, 'The disposable fixture must generate its own Composer fixture-class loader.');
            $arguments = $command
                |> (static fn(string $line): array => explode(' ', $line))
                |> (static fn(array $words): array => array_slice($words, 1));
            $executor->run(new ProcessSpec($arguments));
            $after = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $probe, $directory]));
            self::assertSame(['workload' => true, 'codec' => true, 'handler' => true, 'phpunit' => false], json_decode($after->stdout, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($metadata, file_get_contents($directory . '/vendor/composer/installed.json'));
            self::assertDirectoryDoesNotExist($directory . '/vendor/phpunit');
        } finally {
            try {
                $executor->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
            } finally {
                $executor->close();
            }
        }
    }

    public function testAutoscalingSamplerLoadsToolingWithNoApplicationDevelopmentDependencies(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/baander-production-fixture-loader-' . (random_bytes(8) |> bin2hex(...));
        foreach (['scripts', 'packages', 'tests/Fixtures/Worker', 'tests/Fixtures/Http'] as $path) {
            mkdir($directory . '/' . $path, 0700, true);
        }
        file_put_contents($directory . '/composer.json', '{"name":"baander/production-fixture-loader","require":{"php":">=8.5"}}');
        foreach (['composer.json', 'composer.lock', 'bootstrap.php'] as $name) {
            copy($root . '/scripts/' . $name, $directory . '/scripts/' . $name);
        }
        symlink($root . '/packages/baander-tooling', $directory . '/packages/baander-tooling');
        copy($root . '/tests/Fixtures/Worker/autoscaling-sample.php', $directory . '/tests/Fixtures/Worker/autoscaling-sample.php');
        $entrypoints = [
            'Worker/legacy-runtime.php' => 'Legacy Supervisor fixture takes no arguments',
            'Http/dns-server.php' => 'Missing value for --invalid',
            'Http/dns-orphan-supervisor.php' => 'Usage: dns-orphan-supervisor.php PHP PARENT',
        ];
        foreach ($entrypoints as $path => $message) {
            copy($root . '/tests/Fixtures/' . $path, $directory . '/tests/Fixtures/' . $path);
        }
        file_put_contents($directory . '/stop', 'ready');
        $environment = array_replace(getenv(), ['COMPOSER_DISABLE_NETWORK' => '1', 'BAANDER_TEST_SAMPLER_STOP_FILE' => $directory . '/stop', 'BAANDER_WORKER_IPC_DIRECTORY' => $directory . '/ipc']);
        $executor = new EngineExecutor(ExecutionContext::bounded(60, $environment));
        try {
            $executor->run(new ProcessSpec(['composer', '--working-dir=' . $directory, 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-scripts', '--no-plugins']));
            $executor->run(new ProcessSpec(['composer', '--working-dir=' . $directory . '/scripts', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins']));
            $probe = $executor->run(new ProcessSpec([PHP_BINARY, '-r', <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
if (class_exists(\Baander\Tooling\Fixtures\Worker\CompletionCursor::class) || class_exists(\PHPUnit\Framework\TestCase::class)) { exit(81); }
require $argv[1] . '/scripts/vendor/autoload.php';
foreach ([\Baander\Tooling\Fixtures\Worker\CompletionCursor::class, \Baander\Tooling\Fixtures\Worker\EvidenceMode::class, \Baander\Tooling\Fixtures\Worker\AutoscalingStatusObservation::class] as $class) {
    if (!class_exists($class) && !enum_exists($class)) { exit(82); }
}
echo 'production-tooling-ready';
PHP, $directory]));
            self::assertSame('production-tooling-ready', $probe->stdout);
            $sample = $executor->run(new ProcessSpec([PHP_BINARY, $directory . '/tests/Fixtures/Worker/autoscaling-sample.php', 'execution', '1']), new CompletionPolicy(null));
            self::assertSame(0, $sample->exitCode, $sample->stderr);
            self::assertSame('', $sample->stderr);
            $observation = json_decode($sample->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('execution', $observation['unit']);
            self::assertGreaterThanOrEqual($observation['monotonic'], $observation['capturedAt']);
            foreach ($entrypoints as $path => $message) {
                $failure = $executor->run(new ProcessSpec([PHP_BINARY, $directory . '/tests/Fixtures/' . $path, '--invalid']), new CompletionPolicy(null));
                self::assertSame(1, $failure->exitCode, $failure->stderr);
                self::assertStringContainsString($message, $failure->stderr);
            }
        } finally {
            try {
                $executor->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
            } finally {
                $executor->close();
            }
        }
    }

    public function testVendorAutoloadDoesNotRequireTheToolingFixtureTree(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/baander-fixture-autoload-' . bin2hex(random_bytes(8));
        mkdir($directory . '/vendor/composer', 0700, true);
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            copy($root . '/vendor/autoload.php', $directory . '/vendor/autoload.php');
            foreach (glob($root . '/vendor/composer/*.php') as $file) {
                copy($file, $directory . '/vendor/composer/' . basename($file));
            }
            foreach (glob($root . '/vendor/*') as $dependency) {
                if (in_array(basename($dependency), ['composer', 'autoload.php'], true)) {
                    continue;
                }
                symlink($dependency, $directory . '/vendor/' . basename($dependency));
            }
            $result = $executor->run(new ProcessSpec([
                PHP_BINARY, '-d', 'display_errors=1', '-r',
                'require $argv[1]; echo "root-loader-ready";',
                $directory . '/vendor/autoload.php',
            ]), new CompletionPolicy(null));
            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame('root-loader-ready', $result->stdout);
            self::assertSame('', $result->stderr);
            self::assertDirectoryDoesNotExist($directory . '/scripts');
        } finally {
            try {
                $executor->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
            } finally {
                $executor->close();
            }
        }
    }
}
