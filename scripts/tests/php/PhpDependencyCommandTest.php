<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Dependencies\ComposerEvidence;
use Baander\Tooling\Dependencies\Check;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use PHPUnit\Framework\TestCase;

final class PhpDependencyCommandTest extends TestCase
{
    public function testComposerUsesLiteralProjectAndPrivateEnvironmentWithoutMutatingSources(): void
    {
        $root = sys_get_temp_dir() . '/baander dependencies ' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        mkdir($root . '/bin');
        mkdir($root . '/home');
        mkdir($root . '/project');
        file_put_contents($root . '/project/composer.json', '{"name":"baander/fixture"}');
        file_put_contents($root . '/project/composer.lock', '{"packages":[],"packages-dev":[]}');
        $stub = <<<'PHP_STUB'
<?php
$record = ['argv' => array_slice($argv, 1), 'cwd' => getcwd(), 'environment' => getenv()];
file_put_contents(dirname(__DIR__) . '/calls.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (in_array('--version', $argv, true)) {
    echo 'Composer version 2.11.1 2026-10-30 14:52:10';
} elseif (in_array('validate', $argv, true)) {
    echo './composer.json is valid';
} elseif (in_array('audit', $argv, true)) {
    echo '{"advisories":[],"abandoned":[],"filter":[],"cooldown":[]}';
} else {
    echo '{"locked":[]}';
}
PHP_STUB;
        file_put_contents($root . '/bin/composer', '#!' . PHP_BINARY . "\n" . $stub);
        chmod($root . '/bin/composer', 0700);
        $environment = ['PATH' => $root . '/bin:' . (getenv('PATH') ?: ''), 'HOME' => $root, 'COMPOSER' => '/foreign/composer.json', 'COMPOSER_VENDOR_DIR' => '/foreign/vendor', 'COMPOSER_HOME' => '/foreign/home', 'COMPOSER_DISABLE_NETWORK' => '1'];
        $executor = new EngineExecutor(ExecutionContext::bounded(10, $environment));
        $before = [hash_file('sha256', $root . '/project/composer.json'), hash_file('sha256', $root . '/project/composer.lock')];
        try {
            $composer = new ComposerEvidence($executor, 2, $environment, $root . '/home');
            self::assertSame('2.11.1', $composer->version());
            self::assertSame(0, $composer->validate($root . '/project', true)->exitCode);
            self::assertSame([], $composer->audit($root . '/project')['advisories']);
            $calls = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root . '/calls.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertCount(3, $calls);
            foreach ($calls as $call) {
                foreach (['--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', '--no-cache'] as $argument) {
                    self::assertContains($argument, $call['argv']);
                }
                self::assertArrayNotHasKey('COMPOSER', $call['environment']);
                self::assertArrayNotHasKey('COMPOSER_VENDOR_DIR', $call['environment']);
                self::assertSame($root . '/home', $call['environment']['COMPOSER_HOME']);
            }
            foreach (['--no-check-all', '--strict', '--check-lock', '--no-check-publish'] as $argument) {
                self::assertContains($argument, $calls[1]['argv']);
            }
            self::assertContains('--working-dir=' . $root . '/project', $calls[1]['argv']);
            self::assertSame($root . '/project', $calls[1]['cwd']);
            self::assertSame('1', $calls[1]['environment']['COMPOSER_DISABLE_NETWORK']);
            self::assertArrayNotHasKey('COMPOSER_DISABLE_NETWORK', $calls[2]['environment']);
            self::assertSame($before, [hash_file('sha256', $root . '/project/composer.json'), hash_file('sha256', $root . '/project/composer.lock')]);
        } finally {
            $executor->close();
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }
    public function testLocalCollectionValidatesAllSevenManifestsAndFourLocksWithNoOnlineCommands(): void
    {
        $root = sys_get_temp_dir() . '/baander-dependency-projects-' . bin2hex(random_bytes(8));
        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/packages/baander-tooling/resources', 0700, true);
        $projects = [];
        $hashes = [];
        foreach (['composer.json', 'scripts/composer.json', 'relay/composer.json', 'packages/tsduck/composer.json', 'packages/tooling/composer.json', 'packages/rules/composer.json', 'packages/fork/composer.json'] as $index => $path) {
            $directory = dirname($root . '/' . $path);
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($root . '/' . $path, '{"name":"baander/fixture"}');
            $projects[$path] = ['kind' => $index < 4 ? 'locked' : 'package', 'owner' => 'fixture'];
            $hashes[$path] = hash_file('sha256', $root . '/' . $path);
            if ($index < 4) {
                $lock = str_replace('.json', '.lock', $path);
                $projects[$path]['lock'] = $lock;
                file_put_contents($root . '/' . $lock, '{"packages":[],"packages-dev":[]}');
                $hashes[$lock] = hash_file('sha256', $root . '/' . $lock);
            }
        }
        file_put_contents($root . '/packages/baander-tooling/resources/php-dependencies.json', json_encode(['version' => 1, 'projects' => $projects], JSON_THROW_ON_ERROR));
        $stub = <<<'PHP_STUB'
<?php
file_put_contents(dirname(__DIR__) . '/calls.jsonl', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (in_array('--version', $argv, true)) {
    echo 'Composer version 2.11.1 2026-10-30 14:52:10';
} elseif (in_array('validate', $argv, true)) {
    echo './composer.json is valid';
} else {
    exit(70);
}
PHP_STUB;
        file_put_contents($root . '/bin/composer', '#!' . PHP_BINARY . "\n" . $stub);
        chmod($root . '/bin/composer', 0700);
        $environment = ['PATH' => $root . '/bin:' . (getenv('PATH') ?: ''), 'HOME' => $root];
        $executor = new EngineExecutor(ExecutionContext::bounded(15, $environment));
        try {
            $report = (new Check($executor, ToolingConfiguration::resolve(), $environment))->run($root, true);
            self::assertSame(0, $report->exitCode(), $report->render());
            $calls = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root . '/calls.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertCount(8, $calls);
            self::assertCount(4, array_filter($calls, static fn(array $arguments): bool => in_array('--check-lock', $arguments, true)));
            self::assertCount(3, array_filter($calls, static fn(array $arguments): bool => in_array('--no-check-lock', $arguments, true)));
            foreach ($hashes as $path => $hash) {
                self::assertSame($hash, hash_file('sha256', $root . '/' . $path));
            }
        } finally {
            $executor->close();
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

}
