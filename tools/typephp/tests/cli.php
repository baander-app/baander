<?php

declare(strict_types=1);

$binary = $argv[1] ?? throw new RuntimeException('Pass the packaged executable path.');
$directory = sys_get_temp_dir().'/baander-typephp-cli-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$script = $directory.'/arguments.php';
$extensionless = $directory.'/arguments';
$source = '<?php echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR); exit(17);';
file_put_contents($script, $source);
file_put_contents($extensionless, $source);

/** @param list<string> $arguments */
function verifyCommand(array $arguments, string $expectedOutput, int $expectedStatus = 0, string $input = ''): void
{
    $process = proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start packaged CLI.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== $expectedStatus || $output !== $expectedOutput || $errors !== '') {
        throw new RuntimeException(sprintf(
            'Packaged CLI mismatch: %s; status %d (expected %d), stdout %s, stderr %s',
            json_encode($arguments, JSON_THROW_ON_ERROR),
            $status,
            $expectedStatus,
            substr($output, 0, 500),
            substr($errors, 0, 500),
        ));
    }
}

try {
    verifyCommand([$binary, '-r', 'echo "interpreter";'], 'interpreter');
    foreach ([$script, $extensionless] as $path) {
        verifyCommand([$binary, $path, 'one two', '--version', ''], '["one two","--version",""]', 17);
        verifyCommand([$binary, '-f', $path, '--', 'one two'], '["one two"]', 17);
        verifyCommand([$binary, '-d', 'memory_limit=256M', $path, 'value'], '["value"]', 17);
    }
    verifyCommand([$binary, '--'], 'stdin', input: '<?php echo "stdin";');
    verifyCommand(
        [$binary, '-r', '$process = proc_open([PHP_BINARY, $argv[1], "child argument"], [STDIN, STDOUT, STDERR], $pipes); exit(proc_close($process));', '--', $script],
        '["child argument"]',
        17,
    );
    echo "Packaged interpreter, script, argument, stdin and child-process checks passed.\n";
} finally {
    unlink($script);
    unlink($extensionless);
    rmdir($directory);
}
