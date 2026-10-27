<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\{EnrollmentIssuerContract, Runtime};

Runtime::main(static function (): void {
    $args = getopt('', ['python:', 'issuer:']);
    $checks = EnrollmentIssuerContract::run($args['python'] ?? 'python3', $args['issuer'] ?? dirname(__DIR__) . '/tools/issue_enrollment.py');
    echo "PASS: enrollment issuer CLI contract ({$checks} checks)\n";
});
