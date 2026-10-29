#!/usr/bin/env php
<?php

declare(strict_types=1);

$path = getenv('WORKER_OPERATOR_STUB_STATE');
$state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$arguments = array_slice($_SERVER['argv'], 1);
if (in_array($arguments[0] ?? '', ['--host', '--context'], true)) {
    $arguments = array_slice($arguments, 2);
}
$state['calls'][] = $arguments;
$mode = $state['mode'];
if ($arguments[0] === 'create' || (in_array($arguments[0], ['network', 'volume'], true) && ($arguments[1] ?? '') === 'create') || $arguments[0] === 'build') {
    $kind = match ($arguments[0]) {
        'create' => 'container', 'build' => 'image', default => $arguments[0],
    };
    $name = match ($kind) {
        'container' => $arguments[array_search('--name', $arguments, true) + 1],
        'image' => $arguments[array_search('--tag', $arguments, true) + 1],
        default => $arguments[array_key_last($arguments)],
    };
    $state['createdResources'][$kind][$name] = $arguments;
    if ($kind === 'container') {
        $state['created'][$name] = $arguments;
    }
    $state['resources'][$kind][] = $name;
    echo $kind === 'volume' ? $name : hash('sha256', $name);
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    exit(0);
}
if ($arguments[0] === 'start') {
    $key = $arguments[array_key_last($arguments)];
    $name = array_find(array_keys($state['created'] ?? []), static fn(string $name): bool => $name === $key || hash('sha256', $name) === $key);
    if ($name !== null) {
        $arguments = ['run', ...array_slice($state['created'][$name], 1)];
    }
}
if ((in_array($arguments[0], ['container', 'network', 'volume', 'image'], true) && ($arguments[1] ?? '') === 'inspect') || $arguments[0] === 'inspect') {
    $kind = $arguments[0] === 'inspect' ? 'container' : $arguments[0];
    $key = $arguments[array_key_last($arguments)];
    $name = array_find(array_keys($state['createdResources'][$kind] ?? []), static fn(string $name): bool => $name === $key || hash('sha256', $name) === $key || 'sha256:' . hash('sha256', $name) === $key) ?? $key;
    $code = 0;
    if ($mode === 'transport') {
        fwrite(STDERR, 'Docker transport unavailable');
        $code = 125;
    } elseif (in_array('{{.State.ExitCode}}', $arguments, true)) {
        echo '0';
    } elseif (!in_array($name, $state['resources'][$kind] ?? [], true)) {
        fwrite(STDERR, $kind === 'image' ? 'Error response from daemon: No such image: ' . $key : 'Error: No such ' . $kind . ': ' . $key);
        $code = 1;
    } elseif (in_array('{{.Id}}', $arguments, true) || in_array('{{.Name}}', $arguments, true)) {
        echo $kind === 'volume' ? $name : hash('sha256', $name);
    } else {
        $created = $state['createdResources'][$kind][$name] ?? [];
        $labelIndex = array_search('--label', $created, true);
        $labels = $labelIndex === false ? [] : ['org.baander.tooling.run' => explode('=', $created[$labelIndex + 1], 2)[1]];
        echo json_encode([['Id' => ($kind === 'image' ? 'sha256:' : '') . hash('sha256', $name), 'Name' => ($kind === 'container' ? '/' : '') . $name, 'Config' => ['Labels' => $labels], 'Labels' => $labels]], JSON_THROW_ON_ERROR);
    }
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    exit($code);
}
if ($arguments[0] === 'rm') {
    $arguments = ['container', ...$arguments];
}

$code = 0;
if ($arguments[0] === 'info') {
    echo json_encode(['ID' => 'fixture-daemon'], JSON_THROW_ON_ERROR);
} elseif ($arguments[0] === 'unpause') {
    $code = $mode === 'paused-error' ? 1 : 0;
} elseif (($arguments[1] ?? '') === 'ls') {
    $kind = $arguments[0];
    $state['queries'][$kind] = ($state['queries'][$kind] ?? 0) + 1;
    if ($mode === 'transport' || ($mode === 'verification-transport' && $state['queries'][$kind] === 2)) {
        $code = 125;
    } else {
        foreach ($state['resources'][$kind] ?? [] as $name) {
            if (in_array('{{json .}}', $arguments, true)) {
                echo json_encode($kind === 'image'
                    ? ['Repository' => explode(':', $name, 2)[0], 'Tag' => explode(':', $name, 2)[1] ?? 'latest', 'ID' => 'sha256:' . hash('sha256', $name)]
                    : [$kind === 'container' ? 'Names' : 'Name' => $name, 'ID' => hash('sha256', $name)], JSON_THROW_ON_ERROR), "\n";
            } elseif ($kind === 'container') {
                $namespace = in_array($name, ['worker-current', 'control-current', 'worker-retired', 'control-retired'], true) ? 'fixture-namespace' : '';
                echo hash('sha256', $name), '|', $name, '|', $namespace, '|', str_starts_with($name, 'control-') ? 'control' : 'execution', "\n";
            } else {
                echo $name, "\n";
            }
        }
    }
} elseif (($arguments[1] ?? '') === 'rm') {
    $kind = $arguments[0];
    foreach (array_slice($arguments, 2) as $name) {
        if (str_starts_with($name, '-')) {
            continue;
        }
        if ($kind !== 'volume') {
            $name = array_find(
                $state['resources'][$kind],
                static fn(string $candidate): bool => hash('sha256', $candidate) === $name || 'sha256:' . hash('sha256', $candidate) === $name,
            ) ?? $name;
        }
        $blocked = $name === ($state['blocked'] ?? null);
        if ($blocked && $mode === 'remove-failure') {
            $code = 1;
        } elseif (!($blocked && $mode === 'false-removal')) {
            $state['resources'][$kind] = array_values(array_filter($state['resources'][$kind], static fn(string $candidate): bool => $candidate !== $name));
            if ($blocked && $mode === 'remove-error-absent') {
                $code = 1;
            }
        }
    }
} elseif ($arguments[0] === 'top') {
    if (($state['stopped'] ?? false) && ($state['outage'] ?? false)) {
        $code = 1;
    } else {
        echo "PID PPID COMMAND\n101 1 php worker\n102 101 ffmpeg\n";
    }
} elseif ($arguments[0] === 'stop') {
    $state['outage'] = true;
} elseif ($arguments[0] === 'start') {
    $state['outage'] = false;
} elseif ($arguments[0] === 'run') {
    if (in_array('tests/Fixtures/TwoCommand/deployment.php', $arguments, true)) {
        $phase = $arguments[array_key_last($arguments)];
        $state['phase'] = $phase;
        echo "{\"message\":\"Redis cache unavailable\",\"level\":\"WARNING\"}\n";
        if ($mode === 'failed' || ($phase === 'cancel' && ($state['outage'] ?? false))) {
            $code = 1;
        }
        if ($phase === 'dependency' && !($state['outage'] ?? false)) {
            $code = 1;
        }
    } elseif (in_array('tests/Fixtures/TwoCommand/identities.php', $arguments, true)) {
        $action = $arguments[array_key_last($arguments)];
        $input = stream_get_contents(STDIN);
        if ($action === 'capture') {
            if (!str_contains($input, 'ffmpeg') || ($state['outage'] ?? false)) {
                $code = 1;
            }
            echo '{"encoderIdentity":102}';
        } else {
            if (!($state['recovered'] ?? false) || json_decode($input, true)['encoderIdentity'] !== 102) {
                $code = 1;
            }
            echo '{"contained":true}';
        }
    }
} elseif ($arguments[0] === 'cp') {
    if ($mode === 'missing') {
        $code = 1;
    } elseif (str_contains($arguments[1], '/result-')) {
        $receipt = [
            'phase' => $mode === 'wrong-phase' ? 'attach' : $state['phase'],
            'seconds' => 0.1,
            'evidence' => (object) ['queueAdmissionStatus' => 503],
        ];
        if ($mode === 'array-evidence') {
            $receipt['evidence'] = [];
        }
        file_put_contents(
            $arguments[2],
            $mode === 'malformed' ? '{broken' : json_encode($receipt, JSON_THROW_ON_ERROR),
        );
    }
} elseif ($arguments[0] === 'exec') {
    if (array_any($arguments, static fn(string $argument): bool => str_ends_with($argument, '/bin/worker-deployment.php'))) {
        $position = array_find_key($arguments, static fn(string $argument): bool => str_ends_with($argument, '/bin/worker-deployment.php'));
        $action = $arguments[$position + 1];
        $state['recovered'] = true;
        echo json_encode(['action' => $action, 'success' => true, 'result' => []], JSON_THROW_ON_ERROR);
    } elseif (in_array('tests/Fixtures/TwoCommand/processes.php', $arguments, true)) {
        echo '{"encoders":[]}';
    }
} elseif ($arguments[0] !== 'logs' && $arguments[0] !== 'inspect') {
    $code = 126;
}
file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
exit($code);
