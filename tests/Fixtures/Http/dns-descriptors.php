<?php

declare(strict_types=1);

foreach (glob('/proc/self/fd/*') as $descriptor) {
    $target = @readlink($descriptor);
    if ($target !== false && str_contains($target, 'baander-dns-parent-')) {
        exit(9);
    }
}
echo '["8.8.8.8"]';
