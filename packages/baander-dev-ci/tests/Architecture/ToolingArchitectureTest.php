<?php

declare(strict_types=1);

namespace Baander\DevCi\Tests\Architecture;

use Baander\Filesystem\PrivateDirectory;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ToolingArchitectureTest extends TestCase
{
    public function testActualRulesRejectReverseDependenciesAndAcceptSharedInfrastructure(): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/baander.app-tooling-boundaries-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($directory);
        $executor = new EngineExecutor(ExecutionContext::bounded(90, getenv(), $root));
        try {
            $config = Yaml::parseFile($root . '/deptrac-tooling.yaml');
            self::assertArrayNotHasKey('imports', $config);
            self::assertArrayNotHasKey('skip_violations', $config['deptrac']);
            $files = [
                'packages/baander-core/src/Bad.php' => '<?php namespace Baander\\Core; final class Bad { public function value(): object { return new \\Baander\\DevEnvironment\\Host\\HostSetup; } }',
                'packages/baander-core/src/EnvironmentNames.php' => '<?php namespace Baander\\Core; final class EnvironmentNames {}',
                'packages/baander-configuration/src/Good.php' => '<?php namespace Baander\\Configuration; final class Good { public function __construct(private \\Baander\\Core\\EnvironmentNames $names) {} }',
                'packages/baander-configuration/src/Bad.php' => '<?php namespace Baander\\Configuration; final class Bad { public function value(): object { return new \\Baander\\DevEnvironment\\Host\\HostSetup; } }',
                'packages/baander-images/src/Inventory/Bad.php' => '<?php namespace Baander\\Images\\Inventory; final class Bad { public function value(): object { \\cli_helper(); return new \\ScriptHelper; } }',
                'packages/baander-dev-registry/src/Bad.php' => '<?php namespace Baander\\DevRegistry; final class Bad { public function value(): object { return new \\Baander\\RegistryTests\\Runtime; } }',
                'packages/baander-dev-qualification/src/Fixtures/Web/Bad.php' => '<?php namespace Baander\\DevQualification\\Fixtures\\Web; final class Bad { public function value(): object { return new \\Baander\\DevEnvironment\\Docker; } }',
                'packages/baander-dev-environment/src/Host/HostSetup.php' => '<?php namespace Baander\\DevEnvironment\\Host; final class HostSetup {}',
                'packages/baander-containers/src/Docker/Client.php' => '<?php namespace Baander\\Containers\\Docker; final class Client { public function __construct(private \\Baander\\Process\\Execution\\EngineExecutor $executor) {} }',
                'packages/baander-process/src/Execution/EngineExecutor.php' => '<?php namespace Baander\\Process\\Execution; final class EngineExecutor {}',
                'packages/baander-dev-qualification/src/Fixtures/Worker/Good.php' => '<?php namespace Baander\\DevQualification\\Fixtures\\Worker; final class Good { public function __construct(private \\Baander\\Containers\\Docker\\Client $docker) {} }',
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
            self::assertSame(6, $report['Report']['Violations']);
            self::assertGreaterThanOrEqual(2, $report['Report']['Allowed']);
            $messages = [];
            foreach ($report['files'] as $file) {
                foreach ($file['messages'] as $message) {
                    $messages[] = $message['message'];
                }
            }
            $diagnostics = implode("\n", $messages);
            foreach (['(Core on Development)', '(Configuration on Development)', '(Inventory on CLI)', '(Registry on RegistryTests)', '(FixturesWeb on Development)', 'cli_helper', 'ScriptHelper'] as $expected) {
                self::assertStringContainsString($expected, $diagnostics);
            }
            self::assertStringNotContainsString('(Core on Configuration)', $diagnostics);
            self::assertStringNotContainsString('(Configuration on Core)', $diagnostics);
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
