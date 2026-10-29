<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class FixtureAutoloadTest extends TestCase
{
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
