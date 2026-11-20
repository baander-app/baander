<?php

declare(strict_types=1);

$binary = realpath($argv[1] ?? PHP_BINARY);
$application = rtrim($argv[2] ?? '/var/www/html', '/');
$phpAlias = $argv[3] ?? '/usr/local/bin/php';
if ($binary === false || file_get_contents($binary, false, null, 0, 4) !== "\x7fELF") {
    throw new RuntimeException('Pass the unified application ELF.');
}
if (realpath($phpAlias) !== $binary) {
    throw new RuntimeException('The qualification php command must resolve to the unified ELF.');
}
$temporary = sys_get_temp_dir().'/baander-entrypoints-'.bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$probe = $temporary.'/probe.php';
$record = $temporary.'/record.json';
$configuration = $temporary.'/php.ini';
file_put_contents($probe, <<<'PHP'
<?php
register_shutdown_function(static function (): void {
    file_put_contents(getenv('BAANDER_ENTRYPOINT_RECORD'), json_encode([
        'binary' => realpath(PHP_BINARY),
        'executable' => readlink('/proc/self/exe'),
        'argv' => $GLOBALS['argv'],
        'argc' => $GLOBALS['argc'],
        'server_argv' => $_SERVER['argv'],
        'server_argc' => $_SERVER['argc'],
        'script' => $_SERVER['SCRIPT_FILENAME'],
        'self' => $_SERVER['PHP_SELF'],
        'kernel_loaded' => class_exists('App\\Kernel', false),
    ], JSON_THROW_ON_ERROR));
});
PHP);
$ini = getenv('PHPRC');
$settings = is_string($ini) && is_file($ini) ? file_get_contents($ini) : file_get_contents(dirname(__DIR__).'/qualification.ini');
file_put_contents($configuration, $settings.PHP_EOL.'auto_prepend_file="'.$probe.'"'.PHP_EOL);
$environment = [...getenv(), 'PHPRC' => $configuration, 'PHP_INI_SCAN_DIR' => '', 'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'BAANDER_ENTRYPOINT_RECORD' => $record];
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $arguments, ?string $directory = null, ?string $executable = null) use ($binary, $application, $record, $environment, $expect): array {
    if (is_file($record)) {
        unlink($record);
    }
    $process = proc_open([$executable ?? $binary, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory ?? $application, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start unified application ELF.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $metadata = is_file($record) ? json_decode(file_get_contents($record), true, flags: JSON_THROW_ON_ERROR) : null;
    $expect(is_array($metadata), 'Entrypoint metadata missing: '.$stderr);
    $expect($metadata['binary'] === $binary && $metadata['executable'] === $binary, 'Invocation did not execute the unified ELF.');

    return [$status, $stdout, $stderr, $metadata];
};
$selected = static function (array $metadata, string $script, array $arguments) use ($expect): void {
    $expectedArgv = [$script, ...$arguments];
    $expect($metadata['argv'] === $expectedArgv && $metadata['server_argv'] === $expectedArgv, 'Selector changed argument boundaries or retained its token.');
    $expect($metadata['argc'] === count($expectedArgv) && $metadata['server_argc'] === count($expectedArgv), 'Selector argument counts disagree.');
    $expect($metadata['script'] === $script && $metadata['self'] === $script, 'Selector script metadata is not canonical.');
};

try {
    $console = $application.'/bin/console';
    $operator = $application.'/bin/worker-deployment.php';
    foreach ([['list', '--raw'], ['--version'], ['baander:nonexistent-entrypoint-command']] as $arguments) {
        [$status, $stdout, $stderr, $metadata] = $run(['console', ...$arguments]);
        $selected($metadata, $console, $arguments);
        [$directStatus, $directOut, $directErr] = $run([$console, ...$arguments]);
        if ($arguments[0] === 'baander:nonexistent-entrypoint-command') {
            // Monolog adds a fresh timestamp and correlation ID for each process.
            $normalizeError = static function (string $error): array {
                [$record, $diagnostic] = explode("\n", $error, 2);
                $record = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
                unset($record['datetime'], $record['extra']['correlation_id']);

                return [$record, $diagnostic];
            };
            $error = $normalizeError($stderr);
            $expect([$status, $stdout, $error] === [$directStatus, $directOut, $normalizeError($directErr)], 'Unknown console command diagnostics differ between selector and script.');
        } else {
            $expect([$status, $stdout, $stderr] === [$directStatus, $directOut, $directErr], 'Console selector differs from the existing console script.');
        }
        if ($arguments[0] === 'list') {
            $expect($status === 0 && preg_match('/^list\s/m', $stdout) === 1, 'Console selector did not list real commands.');
        } elseif ($arguments[0] === '--version') {
            $expect($status === 0 && trim($stdout) !== '', 'Console selector did not report its version.');
        } else {
            $expect($status === 1 && $stdout === ''
                && $error[0]['context']['exception']['class'] === \Symfony\Component\Console\Exception\NamespaceNotFoundException::class
                && $error[0]['context']['exception']['message'] === 'There are no commands defined in the "baander" namespace.', 'Unknown console command did not preserve its namespace error.');
        }
    }

    foreach ([['--help'], ['invalid action', '--literal-option', '']] as $arguments) {
        [$status, $stdout, $stderr, $metadata] = $run(['operator', ...$arguments]);
        $selected($metadata, $operator, $arguments);
        $expect($metadata['kernel_loaded'] === false, 'Operator selector loaded the Symfony application kernel.');
        [$directStatus, $directOut, $directErr] = $run([$operator, ...$arguments]);
        $expect([$status, $stdout, $stderr] === [$directStatus, $directOut, $directErr], 'Operator selector differs from its existing script.');
        if ($arguments === ['--help']) {
            $expect($status === 0 && str_contains($stdout, 'Usage: php bin/worker-deployment.php'), 'Operator selector did not show its own help.');
        } else {
            $expect($status === 2 && json_decode($stdout, true, flags: JSON_THROW_ON_ERROR) === ['success' => false, 'action' => null, 'error' => 'invalid_configuration'], 'Invalid operator invocation changed its error contract.');
        }
    }

    foreach ([[], ['unknown-selector']] as $arguments) {
        [$status, $stdout, $stderr, $metadata] = $run($arguments);
        $expect($status === 2 && $stdout === '' && str_starts_with($stderr, 'Usage: baander console'), 'Missing or unknown selector did not return usage and status 2.');
        $expect($metadata['kernel_loaded'] === false, 'Invalid selector started the application.');
    }

    foreach (['console', 'operator'] as $name) {
        file_put_contents($temporary.'/'.$name, '<?php echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR); exit(17);');
        $arguments = $name === 'console' ? ['--version'] : ['--help'];
        [$status, , , $metadata] = $run([$name, ...$arguments], $temporary);
        $expect($status === 0, 'Current-directory file shadowed the reserved '.$name.' selector.');
        $selected($metadata, $name === 'console' ? $console : $operator, $arguments);
        foreach ([['./'.$name], ['-f', $name, '--']] as $scriptArguments) {
            [$status, $stdout] = $run([...$scriptArguments, 'one two', ''], $temporary);
            $expect($status === 17 && $stdout === '["one two",""]', 'Explicit script escape failed for '.$name.'.');
        }
    }

    foreach ([$console, $operator] as $script) {
        $arguments = $script === $console ? ['--version'] : ['--help'];
        foreach ([['-d', 'memory_limit=256M', $script], ['-dauto_prepend_file='.$probe, '-f', $script, '--'], [substr($script, strlen($application) + 1)]] as $prefix) {
            [$status, $stdout, , $metadata] = $run([...$prefix, ...$arguments]);
            $expect($status === 0 && trim($stdout) !== '', 'Existing script invocation failed.');
            if ($script === $operator) {
                $expect($metadata['kernel_loaded'] === false, 'Operator script loaded the application kernel.');
            }
        }
    }
    foreach ([$console, $operator] as $script) {
        $arguments = $script === $console ? ['--version'] : ['--help'];
        [$status, $stdout] = $run([$script, ...$arguments], executable: $phpAlias);
        $expect($status === 0 && trim($stdout) !== '', 'The qualification php alias failed to execute an existing entry script.');
    }
    echo 'Unified entrypoint checks passed ('.$checks.' assertions).'.PHP_EOL;
} finally {
    foreach (['console', 'operator', 'probe.php', 'record.json', 'php.ini'] as $file) {
        if (is_file($temporary.'/'.$file)) {
            unlink($temporary.'/'.$file);
        }
    }
    rmdir($temporary);
}
