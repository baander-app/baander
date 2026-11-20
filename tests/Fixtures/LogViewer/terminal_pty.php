<?php

declare(strict_types=1);

// Run from the repository root: php tests/Fixtures/LogViewer/terminal_pty.php.

function terminalSymbol(FFI $library, string $name): Closure
{
    // Invoke through FFI: converting its symbols directly to PHP closures can crash PHP 8.5.
    return static fn(mixed ...$arguments): mixed => $library->{$name}(...$arguments);
}

function terminalDescriptor(FFI\CData $descriptor): int
{
    $value = unpack('i', FFI::string(FFI::addr($descriptor), FFI::sizeof($descriptor)));
    if ($value === false || !is_int($value[1] ?? null)) {
        throw new RuntimeException('Cannot read terminal descriptor.');
    }
    return $value[1];
}

function checkTerminal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function terminalSetting(string $path, string ...$settings): string
{
    $process = proc_open(['stty', '-F', $path, ...$settings], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    checkTerminal(is_resource($process), 'Cannot run stty.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    checkTerminal(proc_close($process) === 0, 'Cannot configure terminal: ' . $error);
    return trim($output);
}

/** @param resource $master */
function receiveTerminal($master, float $timeout = 2.0, bool $untilScreen = true): string
{
    $deadline = hrtime(true) / 1e9 + $timeout;
    $output = '';
    while (hrtime(true) / 1e9 < $deadline) {
        $read = [$master];
        $write = $except = [];
        if (stream_select($read, $write, $except, 0, 50_000) > 0) {
            $chunk = fread($master, 65_536);
            if ($chunk === false || ($chunk === '' && feof($master))) {
                break;
            }
            $output .= $chunk;
            if ($untilScreen && str_contains($output, 'Baander logs')) {
                break;
            }
        }
    }
    return $output;
}

/** @param resource $process
 * @param resource $master
 * @return array{int, string}
 */
function finishTerminal($process, $master): array
{
    $deadline = hrtime(true) / 1e9 + 8.0;
    $output = '';
    do {
        $output .= receiveTerminal($master, 0.05, false);
        $status = proc_get_status($process);
        if (!$status['running']) {
            return [$status['exitcode'], $output];
        }
    } while (hrtime(true) / 1e9 < $deadline);
    throw new RuntimeException('Terminal command exceeded its eight-second exit deadline.');
}

function runTerminalCase(string $action): void
{
    $libc = FFI::cdef('int openpty(int *master, int *slave, char *name, const void *termios, const void *winsize); int close(int fd);');
    /** @var Closure(FFI\CData, FFI\CData, FFI\CData, ?FFI\CData, ?FFI\CData): int $openpty */
    $openpty = terminalSymbol($libc, 'openpty');
    /** @var Closure(int): int $close */
    $close = terminalSymbol($libc, 'close');
    $masterFd = $libc->new('int');
    $slaveFd = $libc->new('int');
    $name = $libc->new('char[256]');
    checkTerminal($openpty(FFI::addr($masterFd), FFI::addr($slaveFd), $name, null, null) === 0, 'Cannot allocate a terminal.');
    $path = FFI::string($name);
    $masterDescriptor = terminalDescriptor($masterFd);
    $slaveDescriptor = terminalDescriptor($slaveFd);
    $master = fopen('php://fd/' . $masterDescriptor, 'r+');
    $slave = fopen('php://fd/' . $slaveDescriptor, 'r+');
    $close($masterDescriptor);
    $close($slaveDescriptor);
    checkTerminal(is_resource($master) && is_resource($slave), 'Cannot open terminal streams.');
    stream_set_blocking($master, false);
    $process = null;
    try {
        $before = terminalSetting($path, '-g');
        terminalSetting($path, 'rows', '24', 'cols', '80');
        $environment = [
            ...getenv(),
            'LOG_PTY_THROW' => $action === 'exception' ? '1' : '0',
            'LOG_PTY_SQL' => $action === 'paged-sql' ? '1' : '0',
            'LOG_PTY_CHANNELS' => $action === 'channels' ? '1' : '0',
        ];
        $arguments = [PHP_BINARY, __DIR__ . '/terminal.php', 'app:log:view'];
        if ($action === 'paged-sql') {
            array_push($arguments, '--source=archive', '--level=ERROR', '--search=error', '--channel=worker', '--channel=scheduler', '--correlation-id=abc', '--since=2026-11-01T00:00:00Z', '--until=2026-11-20T00:00:00Z', '--limit=12', '--order=asc');
        }
        $process = proc_open($arguments, [0 => $slave, 1 => $slave, 2 => $slave], $pipes, null, $environment);
        checkTerminal(is_resource($process), 'Cannot start terminal command.');
        $output = receiveTerminal($master);
        checkTerminal(str_contains($output, 'Baander logs'), $action . ': missing screen: ' . $output);
        if ($action === 'resize') {
            terminalSetting($path, 'rows', '12', 'cols', '35');
            proc_terminate($process, 28);
            $output = receiveTerminal($master);
            checkTerminal(str_contains($output, 'Baander logs'), 'Resize did not redraw the screen.');
            foreach (array_slice(explode("\r\n", $output), 1) as $line) {
                checkTerminal(mb_strlen($line) <= 35, 'Resized line exceeds terminal width: ' . $line);
            }
        }
        if ($action === 'channels') {
            fwrite($master, "cworker, scheduler\r");
            $output = receiveTerminal($master, 0.5, false);
            checkTerminal(str_contains($output, 'CHANNELS:["worker","scheduler"]'), 'Channel selection missing: ' . $output);
            fwrite($master, "c\r");
            $output = receiveTerminal($master, 0.5, false);
            checkTerminal(str_contains($output, 'CHANNELS:[]'), 'Channel reset missing: ' . $output);
            fwrite($master, 'q');
        } elseif ($action === 'paged-sql') {
            fwrite($master, "]:SELECT count(*) FROM logs\r");
            $output .= receiveTerminal($master, 0.5, false);
            checkTerminal(str_contains($output, 'SQL_FILTERS_OK'), 'SQL filter check missing: ' . $output);
            fwrite($master, 'q');
        } elseif (in_array($action, ['quit', 'resize'], true)) {
            fwrite($master, 'q');
        } elseif ($action === 'eof') {
            fwrite($master, "\x04");
        } elseif ($action === 'exception') {
            fwrite($master, 'F');
        } else {
            proc_terminate($process, $action === 'interrupt' ? 2 : 15);
        }
        [$exitCode, $finalOutput] = finishTerminal($process, $master);
        $output .= $finalOutput . receiveTerminal($master, 0.4, false);
        checkTerminal(terminalSetting($path, '-g') === $before, $action . ': terminal mode changed.');
        checkTerminal(str_contains($output, "\x1b[?25h\x1b[?1049l"), $action . ': missing screen/cursor restoration.');
        checkTerminal($exitCode === ($action === 'exception' ? 1 : 0), $action . ': unexpected exit ' . $exitCode . ': ' . $output);
    } finally {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        fclose($master);
        fclose($slave);
    }
}

foreach (['quit', 'interrupt', 'terminate', 'eof', 'exception', 'resize', 'paged-sql', 'channels'] as $case) {
    runTerminalCase($case);
    echo $case, ": restored\n";
}
