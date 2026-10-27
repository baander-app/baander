#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Development\Host;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Runtime\Requirements;

$temporary = null;
$status = 0;
try {
    Requirements::check(extensions: ['sockets']);
    $temporary = Support::temporary('baander-host-check-');
    new Host(new StateStore($temporary), new Runner())->inspect();
    Support::run(['systemctl', '--user', 'show-environment']);
    foreach (['baander.test', 'host-check.baander.test'] as $name) {
        $addresses = socket_addrinfo_lookup($name, '443', ['ai_socktype' => SOCK_STREAM]);
        Support::check($addresses !== false, $name . ' must resolve only to loopback for this development workflow.');
        $resolved = [];
        foreach ($addresses as $address) {
            $info = socket_addrinfo_explain($address);
            $resolved[] = $info['ai_addr']['sin_addr'] ?? $info['ai_addr']['sin6_addr'] ?? '';
        }
        $resolved = $resolved |> array_unique(...) |> array_values(...);
        Support::check($resolved === ['127.0.0.1'], $name . ' must resolve only to loopback for this development workflow.');
    }
    echo "PASS: host DNS ownership, wildcard loopback resolution, port availability, mkcert and user systemd prerequisites\n";
    echo "This read-only check does not establish application readiness or certificate trust.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'HOST PREREQUISITE BLOCKED: ' . $error->getMessage() . "\n");
    $status = 78;
} finally {
    if ($temporary !== null) {
        Support::removeTree($temporary);
    }
}

exit($status);
