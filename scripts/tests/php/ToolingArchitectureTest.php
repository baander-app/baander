<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessSpec};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ToolingArchitectureTest extends TestCase
{
    public function testActualRulesRejectReverseDependenciesAndAcceptSharedInfrastructure(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/baander.app-tooling-boundaries-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($directory);
        $executor = new EngineExecutor(ExecutionContext::bounded(90, getenv(), $root));
        try {
            $config = Yaml::parseFile($root . '/deptrac-tooling.yaml');
            self::assertArrayNotHasKey('imports', $config);
            self::assertArrayNotHasKey('skip_violations', $config['deptrac']);
            $files = [
                'packages/baander-tooling/src/Configuration/Bad.php' => '<?php namespace Baander\\Tooling\\Configuration; final class Bad { public function value(): object { return new \\Baander\\Tooling\\Development\\Host; } }',
                'packages/baander-tooling/src/Inventory/Bad.php' => '<?php namespace Baander\\Tooling\\Inventory; final class Bad { public function value(): object { \\cli_helper(); return new \\ScriptHelper; } }',
                'packages/baander-tooling/src/Registry/Bad.php' => '<?php namespace Baander\\Tooling\\Registry; final class Bad { public function value(): object { return new \\Baander\\RegistryTests\\Runtime; } }',
                'packages/baander-tooling/src/Fixtures/Web/Bad.php' => '<?php namespace Baander\\Tooling\\Fixtures\\Web; final class Bad { public function value(): object { return new \\Baander\\Tooling\\Development\\Docker; } }',
                'packages/baander-tooling/src/Development/Host.php' => '<?php namespace Baander\\Tooling\\Development; final class Host {}',
                'packages/baander-tooling/src/Docker/Client.php' => '<?php namespace Baander\\Tooling\\Docker; final class Client { public function __construct(private \\Baander\\Tooling\\Process\\EngineExecutor $executor) {} }',
                'packages/baander-tooling/src/Process/EngineExecutor.php' => '<?php namespace Baander\\Tooling\\Process; final class EngineExecutor {}',
                'packages/baander-tooling/src/Fixtures/Worker/Good.php' => '<?php namespace Baander\\Tooling\\Fixtures\\Worker; final class Good { public function __construct(private \\Baander\\Tooling\\Docker\\Client $docker) {} }',
                'scripts/fixture-cli.php' => '<?php function cli_helper(): void {} final class ScriptHelper {}',
                'relay/tests/Support/Runtime.php' => '<?php namespace Baander\\RegistryTests; final class Runtime {}',
            ];
            foreach ($files as $relative => $source) {
                PrivateDirectory::prepare(dirname($directory . '/' . $relative));
                file_put_contents($directory . '/' . $relative, $source);
            }
            foreach ($config['deptrac']['paths'] as &$path) {
                $path = $directory . '/' . $path;
                PrivateDirectory::prepare($path);
            }
            unset($path);
            file_put_contents($directory . '/deptrac.yaml', Yaml::dump($config, 10, 2));
            $result = $executor->run(new ProcessSpec([
                PHP_BINARY, $root . '/vendor/bin/deptrac', 'analyse', '--config-file=' . $directory . '/deptrac.yaml',
                '--no-cache', '--no-progress', '--formatter=json', '--output=' . $directory . '/result.json',
            ], processGroup: true), new CompletionPolicy([1]));
            self::assertSame(1, $result->exitCode, $result->stdout . $result->stderr);
            $report = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $report['Report']['Errors']);
            self::assertSame(0, $report['Report']['Warnings']);
            self::assertSame(0, $report['Report']['Skipped violations']);
            self::assertSame(5, $report['Report']['Violations']);
            self::assertGreaterThanOrEqual(2, $report['Report']['Allowed']);
            $messages = [];
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    $messages[] = $message['message'];
                }
            }
            $diagnostics = implode("\n", $messages);
            foreach (['(Configuration on Development)', '(Inventory on CLI)', '(Registry on RegistryTests)', '(FixturesWeb on Development)', 'cli_helper', 'ScriptHelper'] as $expected) {
                self::assertStringContainsString($expected, $diagnostics);
            }
            self::assertStringNotContainsString('(Docker on Process)', $diagnostics);
            self::assertStringNotContainsString('(FixturesWorker on Docker)', $diagnostics);
        } finally {
            $executor->close();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
