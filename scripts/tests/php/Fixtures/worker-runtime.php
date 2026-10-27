<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Baander\Tooling\Fixtures\Runtime;

$runtime = new Runtime(dirname(__DIR__, 4), 5);
$status = 0;
try {
    $mode = $_SERVER['argv'][1];
    $arguments = match ($mode) {
        'exit' => [PHP_BINARY, '-r', 'fwrite(STDERR,"original child failure"); exit(7);'],
        'timeout' => [PHP_BINARY, '-r', 'echo "before timeout"; usleep(2000000);'],
        'term', 'int' => [
            PHP_BINARY,
            '-r',
            'Swoole\\Process::kill((int)$argv[1], (int)$argv[2]); usleep(2000000);',
            (string) getmypid(),
            $mode === 'term' ? '15' : '2',
        ],
    };
    $runtime->run($arguments, timeout: $mode === 'timeout' ? .1 : 3);
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    $status = Runtime::status($error);
} finally {
    $runtime->close();
}
exit($status);
