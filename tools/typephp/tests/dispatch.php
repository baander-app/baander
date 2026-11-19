<?php

declare(strict_types=1);

$dispatcher = $argv[1] ?? '/usr/local/bin/php';
$probe = tempnam(sys_get_temp_dir(), 'baander-dispatch-');
file_put_contents($probe, '<?php echo basename(PHP_BINARY); exit(0);');
$operator = '/var/www/html/bin/worker-deployment.php';
$cases = [
    [['-d', 'auto_prepend_file='.$probe, $operator, '--help'], 'baander-operator'],
    [['-dauto_prepend_file='.$probe, '-f', $operator, '--', '--help'], 'baander-operator'],
    [['-n', '-d', 'auto_prepend_file='.$probe, 'bin/worker-deployment.php', '--help'], 'baander-operator'],
    [['-d', 'auto_prepend_file='.$probe, 'bin/console', $operator], 'baander'],
];

try {
    foreach ($cases as [$arguments, $expected]) {
        $process = proc_open([$dispatcher, ...$arguments], [STDIN, ['pipe', 'w'], STDERR], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start qualification dispatcher.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        if ($status !== 0 || $output !== $expected) {
            throw new RuntimeException(sprintf('Expected %s, got %s (status %d).', $expected, $output, $status));
        }
    }
    echo "Qualification dispatcher executable checks passed.\n";
} finally {
    unlink($probe);
}
