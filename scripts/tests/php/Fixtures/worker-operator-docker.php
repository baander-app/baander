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
$code = 0;
if ($arguments[0] === 'unpause') {
    $code = $mode === 'paused-error' ? 1 : 0;
} elseif (($arguments[1] ?? '') === 'ls') {
    $kind = $arguments[0];
    $state['queries'][$kind] = ($state['queries'][$kind] ?? 0) + 1;
    if ($mode === 'transport' || ($mode === 'verification-transport' && $state['queries'][$kind] === 2)) {
        $code = 125;
    } else {
        foreach ($state['resources'][$kind] as $name) {
            if ($kind === 'container') {
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
        if ($kind === 'container') {
            $name = array_find(
                $state['resources'][$kind],
                static fn(string $candidate): bool => hash('sha256', $candidate) === $name,
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
    if (in_array('/var/www/html/bin/worker-deployment.php', $arguments, true)) {
        $position = array_search('/var/www/html/bin/worker-deployment.php', $arguments, true);
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
