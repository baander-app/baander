<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class DeveloperHelpersTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/baander-developer-helpers-' . bin2hex(random_bytes(8));
        mkdir($this->work, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->work) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->work . '/' . $name);
            }
        }
        rmdir($this->work);
    }

    public function testProseAuditRetainsCharacterizedCountsAndReportOnlyExit(): void
    {
        $old = <<<'TEXT'
# Contract 1.2

Use `alpha` and `alpha`, `can't`, `"quoted"` and `path\file`.
We MUST use 12 units and never 3; cannot and can't differ.
Always use only required fields; shall retain §2.
```text
`one
across`
```
| old | 42 |
---
TEXT;
        $new = <<<'TEXT'
# Contract 1.3

Use `alpha`, `beta`, `"quoted"`, and `path\file`.
We may use 12 units; cannot, cannot replace can't.
Only required fields retain §2 and §3.
| new | 43 |
TEXT;
        $report = <<<'TEXT'
backtick tokens lost:   {'`alpha`': 1, "`can't`": 1}
backtick tokens gained: {'`beta`': 1}
numbers lost:   {'1.2': 1, '42': 1}
numbers gained: {'1.3': 1, '43': 1}
must: 1 -> 0   <<< DELTA -- trace to a line, justify or revert
never: 1 -> 0   <<< DELTA -- trace to a line, justify or revert
cannot: 1 -> 2   <<< DELTA -- trace to a line, justify or revert
can't: 2 -> 1   <<< DELTA -- trace to a line, justify or revert
always: 1 -> 0   <<< DELTA -- trace to a line, justify or revert
only: 1 -> 1
required: 1 -> 1
shall: 1 -> 0   <<< DELTA -- trace to a line, justify or revert
code fences: 2 -> 0   <<< DELTA
table rows: 1 -> 1
headers: 1 -> 1
§ references: 1 -> 2   (review: added refs must state already-true relations)
structural lines: CHANGED  <<< headers/fences/tables/blanks must not move

audit deltas needing explanation: 9
TEXT;
        file_put_contents($this->work . '/old.md', $old . "\n");
        file_put_contents($this->work . '/new.md', $new . "\n");
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, $this->audit(), $this->work . '/old.md', $this->work . '/new.md']));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame($report . "\n", $result->stdout);
        $same = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, $this->audit(), $this->work . '/old.md', $this->work . '/old.md']));
        self::assertSame(0, $same->exitCode);
        self::assertStringEndsWith("structural lines: identical\n\naudit deltas needing explanation: 0\n", $same->stdout);
        self::assertStringContainsString("can't: 2 -> 2\n", $same->stdout);
    }

    public function testProseAuditPreservesUnicodeNumbersEscapingAndUniversalNewlines(): void
    {
        $old = "`can't \"quote\" \\path\t` ١٢.٣\r\nMUST αmust only_only İONLY SHＡLL\r\n\u{00a0}\r\n";
        $new = "`replacement` ١٢.٤\nMUST αmust only_only İONLY SHＡLL\n\u{00a0}\n";
        $report = <<<'TEXT'
backtick tokens lost:   {'`can\'t "quote" \\path\t`': 1}
backtick tokens gained: {'`replacement`': 1}
numbers lost:   {'١٢.٣': 1}
numbers gained: {'١٢.٤': 1}
must: 1 -> 1
never: 0 -> 0
cannot: 0 -> 0
can't: 1 -> 0   <<< DELTA -- trace to a line, justify or revert
always: 0 -> 0
only: 0 -> 0
required: 0 -> 0
shall: 0 -> 0
code fences: 0 -> 0
table rows: 0 -> 0
headers: 0 -> 0
§ references: 0 -> 0
structural lines: identical

audit deltas needing explanation: 2
TEXT;
        file_put_contents($this->work . '/old.md', $old);
        file_put_contents($this->work . '/new.md', $new);
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, '-n', $this->audit(), $this->work . '/old.md', $this->work . '/new.md']));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame($report . "\n", $result->stdout);
    }

    public function testAllDspServersServeTheirOwnAssetsAndRejectOccupiedPorts(): void
    {
        $runner = new ProcessRunner();
        foreach ([
            'fft2048',
            'spectral_features',
            'dynamics_meter',
            'partitioned_convolver',
            'resampler_hq',
            'loudness_r128',
        ] as $module) {
            $root = dirname(__DIR__, 3) . '/packages/dsp/' . $module;
            $dryRun = $runner->run(new ProcessSpec(argv: ['make', '--no-print-directory', '-n', 'serve-demo'], cwd: $root));
            self::assertSame(0, $dryRun->exitCode, $dryRun->stderr);
            self::assertStringContainsString('php -S 0.0.0.0:8000 -t .', $dryRun->stdout);
            $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
            self::assertIsResource($reservation, $error);
            $address = stream_socket_get_name($reservation, false);
            self::assertIsString($address);
            fclose($reservation);
            $server = $runner->start(new ProcessSpec(argv: [PHP_BINARY, '-S', $address, '-t', '.'], cwd: $root, processGroup: true));
            try {
                $socket = false;
                $deadline = microtime(true) + 3;
                do {
                    $socket = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.05);
                    if ($socket === false) {
                        usleep(10000);
                    }
                } while ($socket === false && microtime(true) < $deadline);
                self::assertIsResource($socket, 'DSP server did not start: ' . $module);
                fwrite($socket, "GET /demo.html HTTP/1.0\r\nHost: baander.app\r\n\r\n");
                stream_set_timeout($socket, 2);
                $response = stream_get_contents($socket);
                fclose($socket);
                self::assertIsString($response);
                self::assertStringContainsString('200 OK', $response);
                self::assertSame(file_get_contents($root . '/demo.html'), explode("\r\n\r\n", $response, 2)[1]);
                $occupied = $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-S', $address, '-t', '.'], cwd: $root, timeout: 1));
                self::assertNotSame(0, $occupied->exitCode);
                self::assertFalse($occupied->timedOut);
                self::assertStringContainsString('Address already in use', $occupied->stderr);
            } finally {
                $server->cancel();
                $runner->wait($server);
            }
            $probe = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.05);
            if (is_resource($probe)) {
                fclose($probe);
            }
            self::assertFalse($probe, 'The owned DSP server remained alive.');
        }
    }

    private function audit(): string
    {
        return dirname(__DIR__, 3) . '/.agents/skills/prose-fix/scripts/audit_rewrite.php';
    }
}
