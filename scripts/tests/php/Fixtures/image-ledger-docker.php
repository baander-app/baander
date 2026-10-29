#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 3);
$path = getenv('IMAGE_LEDGER_STATE');
$state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$state['calls'][] = $arguments;
$save = static function () use (&$state, $path): void {
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
};
$fail = static function (string $message, int $code = 1) use ($save): never {
    $save();
    fwrite(STDERR, $message);
    exit($code);
};
/** @return array<string,mixed>|null */
$find = static function (string $reference) use (&$state): ?array {
    $image = $state['images'][$reference] ?? $state['dangling'][$reference] ?? array_find($state['images'], static fn(array $image): bool => $image['Id'] === $reference);
    if ($image === null) {
        return null;
    }
    $image['RepoTags'] = [];
    $image['RepoDigests'] = [];
    foreach ($state['images'] as $name => $candidate) {
        if ($candidate['Id'] === $image['Id']) {
            $image[str_contains($name, '@') ? 'RepoDigests' : 'RepoTags'][] = $name;
        }
    }
    return $image;
};
foreach ($arguments as $argument) {
    if (str_contains($argument, '%APP_HOME%')) {
        $fail('Unresolved application root in Docker argument', 84);
    }
}
$command = $arguments[0] ?? '';
$verb = $arguments[1] ?? '';
if ($command === 'info') {
    echo in_array('{{.ID}}', $arguments, true) ? $state['daemonId'] : json_encode(['ID' => $state['daemonId']]);
} elseif ($command === 'image' && $verb === 'inspect') {
    $reference = end($arguments);
    $image = $find($reference);
    if ($image === null) {
        $fail('Error response from daemon: No such image: ' . $reference);
    }
    echo in_array('--format', $arguments, true) ? $image['Id'] . "\n" : json_encode([$image]);
} elseif ($command === 'image' && $verb === 'ls') {
    if (($state['mode'] ?? '') === 'list-fails') {
        $fail('image listing unavailable', 7);
    }
    foreach ($state['images'] as $reference => $image) {
        if (in_array('--filter', $arguments, true)) {
            $filter = $arguments[array_search('--filter', $arguments, true) + 1];
            [$label, $value] = explode('=', substr($filter, 6), 2);
            if (($image['Config']['Labels'][$label] ?? null) !== $value) {
                continue;
            }
        }
        $colon = strrpos($reference, ':');
        echo json_encode(['Repository' => substr($reference, 0, $colon), 'Tag' => substr($reference, $colon + 1), 'ID' => $image['Id']]) . "\n";
    }
} elseif ($command === 'image' && $verb === 'tag') {
    [$source, $reference] = array_slice($arguments, 2);
    $receipt = json_decode(file_get_contents(getenv('IMAGE_LEDGER_RECEIPT')), true, flags: JSON_THROW_ON_ERROR);
    if (($receipt['aliases'][$reference] ?? null) !== $source) {
        $fail('Alias intention must be durable before tagging', 81);
    }
    if (isset($state['images'][$reference])) {
        $fail('Attempt to overwrite an existing image reference', 82);
    }
    if (($state['mode'] ?? '') === 'tag-fails-before') {
        $fail('Tag failed before mutation', 9);
    }
    $state['images'][$reference] = $find($source) ?? $fail('Missing source image');
    unset($state['dangling'][$source]);
    if (($state['mode'] ?? '') === 'tag-fails-after') {
        $fail('Tag reply lost after mutation', 9);
    }
} elseif ($command === 'image' && $verb === 'rm') {
    $reference = end($arguments);
    if (str_starts_with($reference, 'sha256:')) {
        $matches = array_keys(array_filter($state['images'], static fn(array $image): bool => $image['Id'] === $reference));
        if (count($matches) > 1) {
            $fail('Image ID has multiple tags', 9);
        }
        foreach ($matches as $name) {
            unset($state['images'][$name]);
        }
    } else {
        $removed = $state['images'][$reference] ?? null;
        unset($state['images'][$reference]);
        if ($removed !== null && !str_contains($reference, '@')) {
            $repository = substr($reference, 0, strrpos($reference, ':'));
            $sameRepositoryTags = array_filter(array_keys($state['images']), static fn(string $name): bool => !str_contains($name, '@') && substr($name, 0, strrpos($name, ':')) === $repository);
            if ($sameRepositoryTags === []) {
                foreach ($state['images'] as $name => $image) {
                    if (str_starts_with($name, $repository . '@') && $image['Id'] === $removed['Id']) {
                        unset($state['images'][$name]);
                    }
                }
            }
        }
    }
} elseif ($command === 'build') {
    $dockerfile = end($arguments) . '/Dockerfile';
    if (is_file($dockerfile)) {
        $contents = file_get_contents($dockerfile);
        $state['dockerfiles'][] = $contents;
        if (str_contains($contents, '%APP_HOME%')) {
            $fail('Unresolved application root in generated Dockerfile', 84);
        }
    }
    $state['buildEnvironments'][] = ['BUILDX_BUILDER' => getenv('BUILDX_BUILDER'), 'BUILDKIT_HOST' => getenv('BUILDKIT_HOST')];
    $resolved = [];
    foreach ($arguments as $index => $argument) {
        if ($argument !== '--build-arg') {
            continue;
        }
        [$key, $reference] = explode('=', $arguments[$index + 1], 2);
        if (!in_array($key, ['FIXTURE_BASE', 'CONTROLLER_BASE', 'DOCKER_CLI'], true)) {
            continue;
        }
        if (str_starts_with($reference, 'sha256:')) {
            $fail('BuildKit interprets raw config IDs as registry references', 83);
        }
        $resolved[$key] = ['reference' => $reference, 'id' => ($find($reference) ?? $fail('Build source does not exist'))['Id']];
    }
    $state['builds'][] = $resolved;
    if (str_starts_with($state['mode'] ?? '', 'retarget-during-build')) {
        foreach ($resolved as $input) {
            $state['images'][$input['reference']] = ['Id' => 'sha256:' . str_repeat('c', 64), 'Config' => ['Labels' => []]];
        }
        if ($state['mode'] === 'retarget-during-build-failure') {
            $fail('Primary build failed', 37);
        }
    }
    $tag = $arguments[array_search('--tag', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $state['images'][$tag] = ['Id' => 'sha256:' . hash('sha256', $tag), 'Config' => ['Labels' => [$key => $value]]];
} elseif ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $id = hash('sha256', $name);
    $state['containers'][$name] = ['Id' => $id, 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]]];
    echo $id;
} elseif ($command === 'rm') {
    $reference = end($arguments);
    foreach ($state['containers'] as $name => $container) {
        if ($name === $reference || $container['Id'] === $reference) {
            unset($state['containers'][$name]);
        }
    }
} elseif ($command === 'container' && $verb === 'inspect') {
    $reference = end($arguments);
    $container = $state['containers'][$reference] ?? array_find($state['containers'], static fn(array $item): bool => $item['Id'] === $reference);
    if ($container === null) {
        $fail('Error: No such container: ' . $reference);
    }
    echo json_encode([$container]);
} elseif ($command === 'container' && $verb === 'ls') {
    foreach ($state['containers'] as $name => $container) {
        echo json_encode(['ID' => $container['Id'], 'Names' => $name]) . "\n";
    }
} elseif ($command === 'inspect' && in_array('{{.State.ExitCode}}', $arguments, true)) {
    echo '0';
} elseif (!in_array($command, ['cp', 'start'], true)) {
    $fail('Unsupported fixture operation: ' . json_encode($arguments), 90);
}
$save();
