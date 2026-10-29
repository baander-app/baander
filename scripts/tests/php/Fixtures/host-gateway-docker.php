<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 4);
if (($arguments[0] ?? '') === 'network' && ($arguments[1] ?? '') === 'inspect') {
    $name = end($arguments);
    echo json_encode([['Name' => $name, 'Id' => hash('sha256', $name),
        'Labels' => ['app.baander.development.owner' => 'gateway-' . substr($name, strlen('baander-development-gateway-'))],
        'IPAM' => ['Config' => [['Subnet' => '172.29.20.0/24']]]]], JSON_THROW_ON_ERROR);
    exit(0);
}
if (is_file($_SERVER['argv'][1] . '/reload-fails') && in_array('reload', $arguments, true)) {
    fwrite(STDERR, 'Gateway reload fixture failed');
    exit(17);
}
require __DIR__ . '/activation-gateway-docker.php';
