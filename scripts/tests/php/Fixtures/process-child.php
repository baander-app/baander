<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];

switch ($argv[1]) {
    case 'echo':
        echo json_encode(['argv' => array_slice($argv, 2), 'cwd' => getcwd(), 'env' => getenv('BAANDER_PROCESS_TEST')], JSON_THROW_ON_ERROR);
        fwrite(STDERR, 'diagnostic');
        exit(7);
    case 'large':
        for ($i = 0; $i < 2048; ++$i) {
            fwrite(STDOUT, str_repeat('o', 8192));
            fwrite(STDERR, str_repeat('e', 8192));
        }
        break;
    case 'streams':
        for ($i = 0; $i < 256; ++$i) {
            fwrite(STDOUT, str_repeat('o', 8192));
            fwrite(STDERR, str_repeat('e', 8192));
        }
        echo hash('sha256', stream_get_contents(STDIN));
        break;
    case 'sleep':
        usleep(30_000_000);
        break;
    case 'descendant':
        $child = proc_open([PHP_BINARY, __FILE__, 'sleep'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        echo proc_get_status($child)['pid'], "\n";
        fflush(STDOUT);
        // Avoid proc_close waiting for the descendant: terminate this direct child immediately.
        exit(0);
}
