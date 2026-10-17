<?php

declare(strict_types=1);

switch (($argv ?? [])[1] ?? '') {
    case 'slow.baander.app':
        usleep(2_000_000);
        break;
    case 'malformed.baander.app':
        echo '{';
        exit(0);
    case 'nonzero.baander.app':
        echo '["8.8.8.8"]';
        exit(7);
    case 'oversized.baander.app':
        echo json_encode(array_fill(0, 10_000, '8.8.8.8'), JSON_THROW_ON_ERROR);
        exit(0);
    case 'environment.baander.app':
        if (getenv('BAANDER_DNS_SECRET') !== false) {
            exit(8);
        }
        break;
}
echo json_encode(['8.8.8.8', '2001:4860:4860::8888', '8.8.8.8'], JSON_THROW_ON_ERROR);
