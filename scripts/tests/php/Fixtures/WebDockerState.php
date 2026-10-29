<?php

declare(strict_types=1);

/** Stateful Docker boundary used by the disposable web workflow tests.
 * @param list<string> $arguments
 */
function webDockerState(array $arguments, string $trace): void
{
    if (array_slice($arguments, 0, 2) === ['context', 'inspect']) {
        echo json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///web-fixture.sock']]]]);
        exit(0);
    }
    if (($arguments[0] ?? '') === 'info') {
        echo '{"ID":"web-fixture-daemon"}';
        exit(0);
    }
    if (array_slice($arguments, 0, 2) === ['image', 'inspect']) {
        if (in_array('{{.Id}}', $arguments, true)) {
            echo 'sha256:' . str_repeat('a', 64);
        } else {
            echo json_encode([['Config' => ['Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/opt/baander"}'], 'Env' => ['APP_HOME=/opt/baander'], 'WorkingDir' => '/opt/baander']]]);
        }
        exit(0);
    }
    $path = $trace . '.resources';
    $resources = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    $kind = $arguments[0] === 'create' ? 'container' : $arguments[0];
    if ($arguments[0] === 'create' || in_array($kind, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'create') {
        if (getenv('WEB_RUNTIME_MODE') === 'early-failure') {
            fwrite(STDERR, 'network-characterization-failure');
            exit(37);
        }
        $name = $kind === 'container' ? $arguments[array_search('--name', $arguments, true) + 1] : end($arguments);
        $label = $arguments[array_search('--label', $arguments, true) + 1];
        [$key, $value] = explode('=', $label, 2);
        $id = hash('sha256', $name);
        $resources[$name] = ['Id' => $id, 'Name' => ($kind === 'container' ? '/' : '') . $name, 'Config' => ['Labels' => [$key => $value]], 'Labels' => [$key => $value]];
        file_put_contents($path, json_encode($resources));
        echo $id;
        exit(0);
    }
    if (in_array($kind, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'inspect') {
        $name = end($arguments);
        if (isset($resources[$name])) {
            echo json_encode([$resources[$name]]);
            exit(0);
        }
        fwrite(STDERR, "Error: No such {$kind}: {$name}\n");
        exit(1);
    }
    if (in_array($kind, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'ls') {
        exit(0);
    }
    if ($kind === 'rm' || in_array($kind, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'rm') {
        if (in_array(getenv('WEB_RUNTIME_MODE'), ['cleanup-failure', 'fixture-failure'], true)) {
            fwrite(STDERR, 'injected removal failure');
            exit(53);
        }
        $id = end($arguments);
        foreach ($resources as $name => $resource) {
            if ($resource['Id'] === $id) {
                unset($resources[$name]);
            }
        }
        file_put_contents($path, json_encode($resources));
        exit(0);
    }
}
