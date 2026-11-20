<?php

declare(strict_types=1);

$args = array_slice($_SERVER['argv'], 1);
if (in_array($args[0] ?? '', ['--host', '--context'], true)) {
    $args = array_slice($args, 2);
}
$root = getenv('PROBE_ROOT');
file_put_contents($root . '/commands', json_encode($args) . "\n", FILE_APPEND);
$state = is_file($root . '/state') ? json_decode(file_get_contents($root . '/state'), true, flags: JSON_THROW_ON_ERROR) : ['containers' => [], 'networks' => []];
$option = static function (string $name) use ($args): ?string {
    $at = array_search($name, $args, true);
    return $at === false ? null : ($args[$at + 1] ?? null);
};
$status = 0;
$kind = $args[0] ?? '';
$verb = $args[1] ?? '';
if ($args === ['context', 'show']) {
    echo "test\n";
} elseif ($kind === 'context' && $verb === 'inspect') {
    echo json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///messaging.sock']], 'TLSMaterial' => []]]);
} elseif ($kind === 'info') {
    echo '{"ID":"messaging-daemon"}';
} elseif ($kind === 'image' && $verb === 'inspect') {
    $home = getenv('PROBE_APP_HOME') ?: '/var/www/html';
    $id = str_starts_with(end($args), 'sha256:') ? end($args) : 'sha256:' . hash('sha256', end($args));
    echo $option('--format') !== null ? $id : json_encode([['Id' => $id, 'Config' => ['Env' => ['APP_HOME=' . $home], 'WorkingDir' => $home, 'Labels' => ['org.baander.application-layout' => json_encode(['version' => 1, 'root' => $home])]]]]);
} elseif ($kind === 'create' || ($kind === 'network' && $verb === 'create')) {
    $name = $kind === 'create' ? $option('--name') : end($args);
    $id = hash('sha256', $name);
    $label = explode('=', $option('--label') ?? '', 2);
    $state[$kind === 'create' ? 'containers' : 'networks'][$name] = ['Id' => $id, 'Name' => $kind === 'create' ? '/' . $name : $name, 'Config' => ['Labels' => [$label[0] => $label[1] ?? '']], 'Labels' => [$label[0] => $label[1] ?? '']];
    echo $id;
} elseif (in_array($kind, ['container', 'network'], true) && $verb === 'inspect') {
    $name = end($args);
    $entry = $state[$kind === 'container' ? 'containers' : 'networks'][$name] ?? null;
    if ($entry === null) {
        foreach ($state[$kind === 'container' ? 'containers' : 'networks'] as $candidate) {
            if ($candidate['Id'] === $name) {
                $entry = $candidate;
                break;
            }
        }
    }
    if (is_array($entry)) {
        echo json_encode([$entry]);
    } else {
        fwrite(STDERR, 'Error: No such ' . $kind . ': ' . $name);
        $status = 1;
    }
} elseif ($kind === 'rm' || ($kind === 'network' && $verb === 'rm')) {
    if ($kind === 'network' && getenv('PROBE_CLEANUP_FAIL') === '1') {
        fwrite(STDERR, "network removal refused\n");
        $status = 31;
    } else {
        $group = $kind === 'rm' ? 'containers' : 'networks';
        foreach ($state[$group] as $name => $entry) {
            if (is_array($entry) && $entry['Id'] === end($args)) {
                unset($state[$group][$name]);
            }
        }
    }
} elseif ($verb === 'ls') {
    foreach ($state[$kind === 'container' ? 'containers' : 'networks'] as $name => $entry) {
        echo json_encode(['ID' => $entry['Id'], $kind === 'container' ? 'Names' : 'Name' => $name]), "\n";
    }
} elseif ($kind === 'logs') {
    echo 'container diagnostics: ', end($args), "\n";
} elseif ($kind === 'exec') {
    if (in_array('pg_isready', $args, true)) {
        $status = getenv('PROBE_NOT_READY') === '1' ? 1 : 0;
    } elseif (in_array('redis-cli', $args, true)) {
        echo "PONG\n";
    } elseif (in_array('psql', $args, true)) {
        file_put_contents($root . '/sql', stream_get_contents(STDIN));
    } elseif (in_array('php', $args, true)) {
        if (getenv('PROBE_WORK_INTERRUPT') === '1') {
            Swoole\Process::kill((int) file_get_contents($root . '/runner-pid'), 15);
            usleep(1_000_000);
        }
        if (getenv('PROBE_WORK_FAIL') === '1') {
            $status = 7;
            fwrite(STDERR, "fixture workload failed\n");
        } else {
            echo "fixture workload passed\n";
        }
    }
}
file_put_contents($root . '/state', json_encode($state));
exit($status);
