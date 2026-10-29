#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'];
$name = basename($arguments[0]);
if ($name === 'registry-command.php') {
    $name = 'docker';
}
$args = array_slice($arguments, 1);
$state = getenv('REGISTRY_TEST_STATE');
if ($name === 'docker' && ($args[0] ?? '') === '--host') {
    file_put_contents($state . '/targets', $args[1] . "\n", FILE_APPEND);
    $args = array_slice($args, 2);
}
file_put_contents($state . '/calls', $name . ' ' . implode(' ', $args) . "\n", FILE_APPEND);
if ($name === 'docker') {
    if (($args[0] ?? '') === '--host') {
        $args = array_slice($args, 2);
    }
    $store = $state . '/docker-state.json';
    $resources = is_file($store) ? json_decode(file_get_contents($store), true, flags: JSON_THROW_ON_ERROR) : ['containers' => [], 'images' => [], 'volumes' => [], 'networks' => []];
    $resources += ['containers' => [], 'images' => [], 'volumes' => [], 'networks' => []];
    $save = static function () use ($store, &$resources): void {
        file_put_contents($store, json_encode($resources, JSON_THROW_ON_ERROR));
    };
    $intended = static function (string $kind, string $name) use ($args): void {
        $receipt = getenv('REGISTRY_NESTED_RECEIPT');
        if (!$receipt) {
            return;
        }
        $saved = json_decode(file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
        $intent = array_find($saved['resources'], static fn(array $entry): bool => $entry['kind'] === $kind && $entry['name'] === $name);
        $label = $args[array_search('--label', $args, true) + 1];
        if ($intent === null || $intent['id'] !== null || $label !== 'org.baander.tooling.run=' . $saved['token']) {
            fwrite(STDERR, 'Missing durable ownership before Docker creation.');
            exit(87);
        }
    };
    $id = str_repeat('a', 64);
    if (($args[0] ?? '') === 'context') {
        echo ($args[1] ?? '') === 'show' ? "default\n" : json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///var/run/docker.sock']]]]);
    } elseif (($args[0] ?? '') === 'info') {
        echo json_encode(['ID' => 'registry-fixture-daemon']);
    } elseif (($args[0] ?? '') === 'buildx' && ($args[1] ?? '') === 'build') {
        $image = $args[array_search('-t', $args, true) + 1];
        $label = explode('=', $args[array_search('--label', $args, true) + 1], 2);
        $resources['images'][$image] = ['Id' => 'sha256:' . $id, 'Config' => ['Labels' => [$label[0] => $label[1]]]];
        $save();
    } elseif (in_array($args[0] ?? '', ['volume', 'network'], true) && ($args[1] ?? '') === 'create') {
        $kind = $args[0];
        $resource = end($args);
        $intended($kind, $resource);
        $label = explode('=', $args[array_search('--label', $args, true) + 1], 2);
        $resources[$kind . 's'][$resource] = ['Name' => $resource, 'Id' => str_repeat('b', 64), 'Labels' => [$label[0] => $label[1]]];
        $save();
        echo $kind === 'volume' ? $resource : str_repeat('b', 64);
    } elseif (in_array($args[0] ?? '', ['volume', 'network'], true) && ($args[1] ?? '') === 'rm') {
        $kind = $args[0];
        $key = end($args);
        $resources[$kind . 's'] = array_filter($resources[$kind . 's'], static fn(array $entry): bool => ($kind === 'volume' ? $entry['Name'] : $entry['Id']) !== $key);
        $save();
    } elseif (($args[0] ?? '') === 'image' && ($args[1] ?? '') === 'ls') {
        $filter = array_search('--filter', $args, true);
        foreach ($resources['images'] as $tag => $entry) {
            if ($filter !== false && ('label=org.baander.tooling.run=' . ($entry['Config']['Labels']['org.baander.tooling.run'] ?? '')) !== $args[$filter + 1]) {
                continue;
            }
            [$repository, $version] = explode(':', $tag, 2);
            echo json_encode(['Repository' => $repository, 'Tag' => $version, 'ID' => $entry['Id']]), "\n";
        }
    } elseif (($args[0] ?? '') === 'create') {
        $container = $args[array_search('--name', $args, true) + 1];
        $intended('container', $container);
        $label = explode('=', $args[array_search('--label', $args, true) + 1], 2);
        $resources['containers'][$container] = ['Id' => $id, 'Name' => '/' . $container, 'Config' => ['Labels' => [$label[0] => $label[1]]], 'arguments' => $args];
        $save();
        echo $id;
    } elseif (($args[0] ?? '') === 'start') {
        $container = array_find($resources['containers'], static fn(array $entry): bool => $entry['Id'] === end($args));
        $cadence = in_array('tests/run_backup_cadence_contract.php', $container['arguments'], true);
        if (!$cadence && in_array(getenv('STUB_FAIL'), ['encrypted', 'encrypted-cleanup'], true)) {
            exit(27);
        }
        if ($cadence && getenv('STUB_FAIL') === 'cadence') {
            exit(28);
        }
    } elseif (($args[0] ?? '') === 'rm') {
        if (in_array(getenv('STUB_FAIL'), ['cleanup', 'encrypted-cleanup'], true)) {
            exit(7);
        }
        $resources['containers'] = array_filter($resources['containers'], static fn(array $entry): bool => $entry['Id'] !== end($args));
        $save();
    } elseif (($args[1] ?? '') === 'inspect') {
        $kind = $args[0];
        $key = end($args);
        $entries = $resources[$kind === 'image' ? 'images' : $kind . 's'];
        $entry = $entries[$key] ?? ($kind === 'image' ? array_find($entries, static fn(array $item): bool => $item['Id'] === $key) : null);
        if ($entry === null) {
            fwrite(STDERR, 'Error response from daemon: No such ' . $kind . ': ' . $key);
            exit(1);
        }
        echo json_encode([$entry]);
    } elseif (in_array($args[0] ?? '', ['container', 'network', 'volume'], true) && ($args[1] ?? '') === 'ls') {
        $kind = $args[0];
        $format = $args[array_search('--format', $args, true) + 1];
        if ($kind === 'container' && getenv('STUB_FAIL') === 'container-snapshot-size' && ($format === '{{json .}}' || str_contains($format, '.Size'))) {
            fwrite(STDERR, 'snapshotter.Usage failed for unrelated container');
            exit(7);
        }
        foreach ($resources[$kind . 's'] as $entry) {
            echo json_encode(['ID' => $entry['Id'], $kind === 'container' ? 'Names' : 'Name' => ltrim($entry['Name'], '/')]), "\n";
        }
    } elseif (($args[0] ?? '') === 'image' && ($args[1] ?? '') === 'rm') {
        if (getenv('STUB_FAIL') === 'image-cleanup') {
            exit(8);
        }
        $resources['images'] = array_filter($resources['images'], static fn(array $entry): bool => $entry['Id'] !== end($args));
        $save();
    }
} elseif ($name === 'curl') {
    if (getenv('STUB_FAIL') === 'download') {
        fwrite(STDERR, "injected download failure\n");
        exit(19);
    }
    touch($args[array_search('-o', $args, true) + 1]);
} elseif ($name === 'sha256sum') {
    $input = stream_get_contents(STDIN);
    if (!preg_match('/^[a-f0-9]{64}  /', $input)) {
        exit(8);
    }
} elseif ($name === 'git') {
    echo str_repeat('a', 40), "\n";
} elseif ($name === 'tar' && str_contains(implode(' ', $args), 'openssl.tar.gz')) {
    $source = $args[array_search('-C', $args, true) + 1] . '/openssl-3.5.3';
    mkdir($source);
    file_put_contents($source . '/Configure', "#!/bin/sh\nexit 0\n");
    chmod($source . '/Configure', 0755);
} elseif ($name === 'cmake' && in_array('--build', $args, true)) {
    $build = $args[1];
    if (!is_dir($build)) {
        mkdir($build);
    }
    copy(__FILE__, $build . '/registry_parser_fuzz');
    chmod($build . '/registry_parser_fuzz', 0755);
} elseif ($name === 'registry_parser_fuzz') {
    echo "parser output\n";
    if (getenv('STUB_FAIL') === 'fuzz') {
        $prefix = array_find($args, static fn(string $arg): bool => str_starts_with($arg, '-artifact_prefix='));
        file_put_contents(substr($prefix, strlen('-artifact_prefix=')) . 'crash-input', 'input');
        exit(29);
    }
} elseif ($name === 'clang++') {
    $target = $args[array_search('-o', $args, true) + 1];
    file_put_contents($target, "#!/bin/sh\necho 'AddressSanitizer: heap-use-after-free'\nexit 1\n");
    chmod($target, 0755);
} elseif (str_starts_with($name, 'run_') || str_starts_with($name, 'test_')) {
    if (in_array($name, ['run_cluster_contract.php', 'run_restore_contract.php'], true)) {
        if (($position = array_search('--diagnostics', $args, true)) !== false) {
            $directory = $args[$position + 1];
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory . '/node-0.log', "process diagnostics\n");
        }
        if (getenv('STUB_FAIL') === 'cluster' && $name === 'run_cluster_contract.php') {
            echo "injected cluster failure\n";
            exit(23);
        }
        if (in_array(getenv('STUB_FAIL'), ['terminate', 'stubborn'], true) && $name === 'run_cluster_contract.php') {
            if (getenv('STUB_FAIL') === 'stubborn') {
                Swoole\Process::signal(15, static function (): void {});
            }
            $storage = sys_get_temp_dir() . '/qualification-child-' . bin2hex(random_bytes(4));
            mkdir($storage);
            file_put_contents($state . '/storage', $storage);
            file_put_contents($state . '/child', (string) getmypid());
            $deadline = microtime(true) + 60;
            while (microtime(true) < $deadline) {
                usleep(10000);
            }
        }
    }
    echo 'PASS: ', $name, "\n";
}
