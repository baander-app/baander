#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\Runtime;

Runtime::main(static function (): void {
    $arguments = Runtime::arguments(['probe']);
    $result = Runtime::run(
        [$arguments['probe']],
        timeout: 20,
        env: array_replace(getenv(), ['TSAN_OPTIONS' => 'halt_on_error=1:exitcode=66']),
    );
    if ($result['code'] !== 66 || !str_contains($result['stderr'], 'WARNING: ThreadSanitizer: data race')) {
        fwrite(
            STDERR,
            'TSan positive control failed (exit ' . $result['code'] . ").\n" . $result['stdout'] . $result['stderr'],
        );
        throw new RuntimeException('TSan runtime did not detect the deliberate data race.');
    }
    echo "TSan positive control detected the deliberate data race (exit 66).\n";
});
