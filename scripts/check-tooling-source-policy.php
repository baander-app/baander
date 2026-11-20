<?php

declare(strict_types=1);

use Baander\DevCi\Architecture\SourcePolicy;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

Requirements::check(swoole: false);
$violations = SourcePolicy::check(dirname(__DIR__));
foreach ($violations as $violation) {
    fwrite(STDERR, sprintf("%s:%d [%s] %s\n", $violation['path'], $violation['line'], $violation['rule'], $violation['message']));
}
exit($violations === [] ? 0 : 1);
