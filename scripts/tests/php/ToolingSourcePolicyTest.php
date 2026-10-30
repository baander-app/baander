<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Architecture\SourcePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolingSourcePolicyTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function forbiddenSources(): iterable
    {
        yield 'native process' => ['<?php proc_open($argv, [], $pipes);', 'process-engine'];
        yield 'qualified native process' => ['<?php \\proc_open($argv, [], $pipes);', 'process-engine'];
        yield 'native alias' => ['<?php use function proc_open as spawn; spawn($argv, [], $pipes);', 'process-engine'];
        yield 'qualified native alias' => ['<?php use function \\shell_exec as spawn; spawn($command);', 'process-engine'];
        yield 'grouped native alias' => ['<?php use function \\{proc_open as spawn, shell_exec}; spawn($argv, [], $pipes);', 'process-engine'];
        yield 'engine construction' => ['<?php new ProcessRunner();', 'process-runner'];
        yield 'aliased engine' => ['<?php use Baander\\Tooling\\Process\\ProcessRunner as Runner; new Runner();', 'process-runner'];
        yield 'docker argv' => ['<?php $executor->run(["docker", "ps"]);', 'raw-docker'];
        yield 'absolute docker argv' => ['<?php $executor->run(["/usr/bin/docker", "ps"]);', 'raw-docker'];
        yield 'concatenated docker argv' => ['<?php $executor->run(["doc" . "ker", "ps"]);', 'raw-docker'];
        yield 'docker assignment' => ['<?php $executable = "docker"; $executor->run([$executable, "ps"]);', 'raw-docker'];
        yield 'root path' => ['<?php $path = "/var/www/html/vendor/autoload.php";', 'application-root'];
        yield 'concatenated root' => ['<?php $path = "/var/www/" . "html/bin/console";', 'application-root'];
        yield 'application image' => ['<?php $image = "martinjuul/baander-app:latest";', 'default-image'];
        yield 'browser image' => ['<?php $image = "mcr.microsoft.com/playwright:v1.60.0-noble";', 'default-image'];
        yield 'concatenated image' => ['<?php $image = "martinjuul/" . "baander-app:latest";', 'default-image'];
        yield 'manual helper' => ['<?php require __DIR__ . "/helper.php";', 'manual-loader'];
        yield 'manual helper called autoload' => ['<?php $autoload = __DIR__ . "/helper.php"; require $autoload;', 'manual-loader'];
        yield 'obsolete import' => ['<?php use Baander\\Tooling\\Development\\Docker;', 'obsolete-helper'];
        yield 'obsolete grouped import' => ['<?php use Baander\\Tooling\\Development\\{Runner, Docker};', 'obsolete-helper'];
        yield 'obsolete source path' => ['<?php $path = "/Development/Runner.php";', 'obsolete-helper'];
        yield 'generated process' => ["<?php \$probe = <<<'PHP_CODE'\nproc_open(\$argv, [], \$pipes);\nPHP_CODE;", 'process-engine'];
        yield 'generated root' => ["<?php \$probe = <<<'PHP_CODE'\nrequire '/var/www/html/vendor/autoload.php';\nPHP_CODE;", 'application-root'];
        yield 'generated docker' => ["<?php \$probe = <<<'PHP_CODE'\n<?php \$argv = ['docker', 'ps'];\nPHP_CODE;", 'raw-docker'];
        yield 'generated shell docker' => ["<?php \$probe = <<<'SHELL'\ndocker run --rm \$image\nSHELL;", 'raw-docker'];
    }

    #[DataProvider('forbiddenSources')]
    public function testRejectsPolicyBypasses(string $source, string $rule): void
    {
        $violations = SourcePolicy::violations('packages/baander-tooling/src/Fixtures/NewWorkflow.php', $source);
        self::assertContains($rule, array_column($violations, 'rule'));
        foreach ($violations as $violation) {
            self::assertSame('packages/baander-tooling/src/Fixtures/NewWorkflow.php', $violation['path']);
            self::assertGreaterThan(0, $violation['line']);
        }
    }

    public function testAllowsMethodsCommentsAndResolvedRuntimeValues(): void
    {
        $source = <<<'PHP_CODE'
            <?php
            // proc_open($argv); docker and /var/www/html are discussed here.
            $db->exec('CREATE TABLE example (id int)');
            $docker->exec($container, $arguments);
            Example::system();
            function exec() {}
            $executor->run([$dockerExecutable, 'ps']);
            $image = $configuration->images->application;
            $dsn = 'redis://default:test-only@redis:6379';
            $role = $state['docker'];
            Runtime::require($ready, 'A contract is required');
            foreach (['docker', 'tar'] as $binary) {}
            PHP_CODE;
        self::assertSame([], SourcePolicy::violations('packages/baander-tooling/src/Fixtures/NewWorkflow.php', $source));
    }

    public function testComposerLoadingRemainsSupported(): void
    {
        self::assertSame([], SourcePolicy::violations('scripts/new-entrypoint.php', '<?php require __DIR__ . "/vendor/autoload.php";'));
        self::assertSame([], SourcePolicy::violations('scripts/bootstrap.php', '<?php $autoload = __DIR__ . "/vendor/autoload.php"; require $autoload;'));
    }

    public function testGeneratedComposerAndLauncherExceptionsRemainCallsiteBound(): void
    {
        foreach ([
            'scripts/tests/php/HttpFixtureTest.php',
            'scripts/tests/php/StaticCheckStagingStatusTest.php',
            'scripts/tests/php/Fixtures/controller-publication-gate.php',
        ] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
            self::assertSame([], SourcePolicy::violations($path, $source));
            $extra = "\nrequire \$unapprovedHelper;";
            self::assertContains('manual-loader', array_column(SourcePolicy::violations($path, $source . $extra), 'rule'));
            self::assertContains('stale-exception', array_column(SourcePolicy::violations($path, str_replace('require ', 'include ', $source)), 'rule'));
            if (str_ends_with($path, 'Test.php')) {
                self::assertContains('stale-exception', array_column(SourcePolicy::violations($path, str_replace('/vendor/autoload.php', '/helper.php', $source)), 'rule'));
            }
        }
    }

    public function testKnownDefaultAssertionsDoNotAuthorizeNewRuntimeDefaults(): void
    {
        $path = 'scripts/tests/php/ApplicationLayoutTest.php';
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertSame([], SourcePolicy::violations($path, $source));
        $extra = <<<'PHP_CODE'
            <?php $root = '/var/www/html/new-default'; $image = 'martinjuul/baander-app:latest';
            PHP_CODE;
        $rules = array_column(SourcePolicy::violations($path, $source . substr($extra, 5)), 'rule');
        self::assertContains('application-root', $rules);
        self::assertContains('default-image', $rules);
    }

    public function testEngineExceptionDoesNotPermitASecondCallInTheSameFile(): void
    {
        $path = 'packages/baander-tooling/src/Process/RunningProcess.php';
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertSame([], SourcePolicy::violations($path, $source));
        self::assertContains('process-engine', array_column(SourcePolicy::violations($path, $source . "\nproc_open(\$argv, [], \$pipes);"), 'rule'));
    }

    public function testExceptionBecomesStaleWhenItsCallIsRemovedOrMoved(): void
    {
        $path = 'packages/baander-tooling/src/Process/RunningProcess.php';
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertContains('stale-exception', array_column(SourcePolicy::violations($path, str_replace('proc_open(', 'approvedExecutor(', $source)), 'rule'));
        self::assertContains('process-engine', array_column(SourcePolicy::violations('packages/baander-tooling/src/Process/NewEngine.php', $source), 'rule'));
    }

    public function testNewNestedFilesAreDiscoveredAndVendorAndSymlinksAreIgnored(): void
    {
        $root = sys_get_temp_dir() . '/baander-source-policy-' . bin2hex(random_bytes(8));
        $directory = $root . '/packages/baander-tooling/src/Fixtures/New';
        mkdir($directory . '/vendor', 0700, true);
        mkdir($root . '/scripts', 0700);
        file_put_contents($root . '/scripts/entrypoint.php', '<?php function compositionProbe() {} class Subject { public function methodOnly() {} }');
        file_put_contents($directory . '/Added.php', <<<'PHP_CODE'
            <?php
            namespace Baander\Tooling\Fixtures;
            use function compositionProbe as aliasProbe;
            proc_open($argv, [], $pipes);
            compositionProbe();
            \compositionProbe();
            aliasProbe();
            $subject->compositionProbe();
            methodOnly();
            PHP_CODE);
        file_put_contents($directory . '/vendor/Ignored.php', '<?php shell_exec($command);');
        symlink($directory . '/Added.php', $directory . '/Linked.php');
        try {
            $violations = SourcePolicy::check($root);
            self::assertCount(4, $violations);
            self::assertSame('packages/baander-tooling/src/Fixtures/New/Added.php', $violations[0]['path']);
            self::assertSame('process-engine', $violations[0]['rule']);
            self::assertSame(['composition-helper', 'composition-helper', 'composition-helper'], array_column(array_slice($violations, 1), 'rule'));
        } finally {
            unlink($directory . '/Linked.php');
            unlink($directory . '/Added.php');
            unlink($directory . '/vendor/Ignored.php');
            unlink($root . '/scripts/entrypoint.php');
            rmdir($root . '/scripts');
            rmdir($directory . '/vendor');
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir(dirname($directory, 2));
            rmdir(dirname($directory, 3));
            rmdir(dirname($directory, 4));
            rmdir($root);
        }
    }
}
