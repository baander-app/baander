#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/scripts/bootstrap.php';
try {
    Baander\Tooling\Runtime\Requirements::check();
    $environment = getenv();
    if (!in_array($environment['BAANDER_TEST_WORKER_LIFECYCLE_PHASE'] ?? '', ['redis', 'postgres'], true)) {
        throw new InvalidArgumentException('Choose a redis or postgres worker lifecycle phase');
    }
    exit(Baander\Tooling\Fixtures\Worker\OperatorQualification::main(
        array_slice($_SERVER['argv'], 1),
        dirname(__DIR__, 3),
    ));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() ?: 2);
} catch (Throwable $error) {
    fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\Tooling\Fixtures\Runtime::status($error));
}
