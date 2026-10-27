<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Runtime\Requirements;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    public function testCurrentToolingHostSatisfiesBaseline(): void
    {
        Requirements::check();
        $this->addToAssertionCount(1);
    }

    public function testCoreHelperUsesStandaloneBootstrap(): void
    {
        [$status, $output] = $this->withoutConfiguration(
            'Requirements::check(false); echo json_encode(['
            . 'class_exists("Composer\\\\Autoload\\\\ClassLoader", false)]);',
        );
        self::assertSame(0, $status, $output);
        self::assertSame('[false]', $output);
    }

    public function testMissingSwooleFailsBeforeStateChange(): void
    {
        [$status, $output] = $this->withoutConfiguration('Requirements::check(); echo "state changed";');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('PHP extension swoole is required', $output);
        self::assertStringNotContainsString('state changed', $output);
    }

    public function testCommandSpecificExtensionHasSeparateDiagnostic(): void
    {
        [$status, $output] = $this->withoutConfiguration('Requirements::check(false, ["yaml"]); echo "state changed";');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('PHP extension yaml is required for this command', $output);
        self::assertStringNotContainsString('swoole', $output);
        self::assertStringNotContainsString('state changed', $output);
    }

    public function testAvailableCommandSpecificExtensionPasses(): void
    {
        [$status, $output] = $this->withoutConfiguration('Requirements::check(false, ["json"]); echo "ready";');
        self::assertSame(0, $status, $output);
        self::assertSame('ready', $output);
    }

    /** @return array{int, string} */
    private function withoutConfiguration(string $body): array
    {
        $loader = dirname(__DIR__, 2) . '/tooling/bootstrap.php';
        $source = 'require ' . var_export($loader, true) . '; '
            . 'use Baander\\Tooling\\Runtime\\Requirements; '
            . $body;
        $process = proc_open([PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', $source], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, '/tmp');
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
