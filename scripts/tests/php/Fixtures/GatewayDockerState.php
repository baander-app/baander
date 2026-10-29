<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

final class GatewayDockerState
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): void
    {
        $root = $arguments[1];
        $arguments = array_slice($arguments, 4);
        $directory = $root . '/fixture-containers';
        is_dir($directory) || mkdir($directory, 0700);
        $files = static fn(): array => glob($directory . '/*.json');
        $find = static function (string $identity) use ($files): ?array {
            foreach ($files() as $file) {
                $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if ($resource['Id'] === $identity || ltrim($resource['Name'], '/') === $identity) {
                    return $resource;
                }
            }
            return null;
        };
        if (($arguments[0] ?? '') === 'info') {
            echo '{"ID":"daemon"}';
        } elseif (($arguments[0] ?? '') === 'network' && ($arguments[1] ?? '') === 'inspect') {
            $name = end($arguments);
            echo json_encode([['Name' => $name, 'Id' => hash('sha256', $name),
                'Labels' => ['app.baander.development.owner' => 'gateway-' . substr($name, strlen('baander-development-gateway-'))],
                'IPAM' => ['Config' => [['Subnet' => '172.20.0.0/16']]]]], JSON_THROW_ON_ERROR);
        } elseif (($arguments[0] ?? '') === 'create') {
            $name = $arguments[array_search('--name', $arguments, true) + 1];
            [$label, $token] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
            $receipt = json_decode(file_get_contents($root . '/gateway/config-probe-resources.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($receipt['token'] !== $token || !array_any($receipt['resources'], static fn(array $resource): bool => $resource['name'] === $name && $resource['id'] === null)) {
                fwrite(STDERR, 'Gateway probe creation preceded durable ownership');
                exit(80);
            }
            $id = hash('sha256', $name);
            file_put_contents($directory . '/' . $id . '.json', json_encode(['Id' => $id, 'Name' => '/' . $name,
                'Config' => ['Labels' => [$label => $token]], 'State' => ['ExitCode' => 0, 'Running' => false]], JSON_THROW_ON_ERROR));
            echo $id;
        } elseif (($arguments[0] ?? '') === 'container' && ($arguments[1] ?? '') === 'inspect') {
            $identity = end($arguments);
            $resource = $find($identity);
            if ($resource === null) {
                fwrite(STDERR, 'Error: No such container: ' . $identity);
                exit(1);
            }
            echo json_encode([$resource], JSON_THROW_ON_ERROR);
        } elseif (($arguments[0] ?? '') === 'container' && ($arguments[1] ?? '') === 'ls') {
            foreach ($files() as $file) {
                $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                echo json_encode(['ID' => $resource['Id'], 'Names' => ltrim($resource['Name'], '/')], JSON_THROW_ON_ERROR) . "\n";
            }
        } elseif (($arguments[0] ?? '') === 'rm') {
            $resource = $find(end($arguments));
            if ($resource !== null) {
                unlink($directory . '/' . $resource['Id'] . '.json');
            }
        } elseif (($arguments[0] ?? '') === 'inspect') {
            $resource = $find(end($arguments));
            if (in_array('--format', $arguments, true)) {
                echo $resource['State']['ExitCode'] ?? '0';
            } else {
                echo json_encode([$resource], JSON_THROW_ON_ERROR);
            }
        } elseif (!in_array($arguments[0] ?? '', ['start', 'compose'], true)) {
            fwrite(STDERR, 'Unexpected activation gateway fixture command');
            exit(79);
        }
    }
}
