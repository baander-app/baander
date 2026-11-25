<?php

declare(strict_types=1);

namespace Baander\Deployment\Tests;

use Baander\Deployment\Observability\LogCollection;
use Baander\Containers\Docker\Target;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LogCollectionTest extends TestCase
{
    public function testCollectorPinsDaemonAndInventoryAndSeparatesStorageFromQueryCredentials(): void
    {
        $target = new Target('/usr/bin/docker', 'unix:///run/user/1000/docker.sock', 'captured-daemon', null, null);
        $groups = [['labels' => ['com.docker.compose.project' => 'preview-baander-app']]];
        $config = LogCollection::configuration('preview-baander-app', $target, $groups);
        $service = LogCollection::service('sha256:' . str_repeat('a', 64), '/srv/baander', $target, '/private/credentials', $config);
        self::assertSame('captured-daemon', $config['expectedDaemonId']);
        self::assertSame($groups, $config['containerGroups']);
        self::assertSame(['type' => 'volume', 'source' => 'docker_proxy', 'target' => '/run/baander-docker-proxy', 'read_only' => true], $service['volumes'][0]);
        self::assertSame('/run/baander-docker-proxy/docker.sock', $config['dockerSocket']);
        self::assertNotContains('/run/user/1000/docker.sock', array_column($service['volumes'], 'source'));
        self::assertSame('/run/user/1000/docker.sock', LogCollection::proxyService('sha256:' . str_repeat('b', 64), $target, 'configuration')['volumes'][0]['source']);
        self::assertContains('label=disable', $service['security_opt']);
        self::assertSame('logs', $service['volumes'][2]['source']);
        self::assertTrue($service['volumes'][2]['read_only']);
        self::assertSame('log_index', $service['volumes'][3]['source']);
        self::assertSame(['web', 'control', 'execution'], array_column($config['fileRoots'], 'service'));
        self::assertSame([true, true, true], array_column($config['fileRoots'], 'optional'));
        $nodes = json_decode(LogCollection::nodes('preview-baander-app', '[{"id":"worker-node","url":"https://logs.worker.baander.app","tokenFile":"/secrets/worker-node"}]'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('http://log-agent:9509', $nodes[0]['url']);
        self::assertSame(LogCollection::QUERY_TOKEN, $nodes[0]['tokenFile']);
        self::assertSame('worker-node', $nodes[1]['id']);
        self::assertNotSame($config['tokenFile'], $config['checkpointTokenFile']);
    }

    public function testGeneratedCredentialsRemainStableAndOnlyQueryFileIsReadableByWeb(): void
    {
        $directory = sys_get_temp_dir() . '/baander-log-credentials-' . (random_bytes(8) |> bin2hex(...));
        try {
            $paths = LogCollection::credentials($directory);
            $query = file_get_contents($paths['query']);
            $checkpoint = file_get_contents($paths['checkpoint']);
            self::assertNotSame($query, $checkpoint);
            self::assertSame(0444, fileperms($paths['query']) & 0777);
            self::assertSame(0600, fileperms($paths['checkpoint']) & 0777);
            LogCollection::credentials($directory);
            self::assertSame($query, file_get_contents($paths['query']));
            self::assertSame($checkpoint, file_get_contents($paths['checkpoint']));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testStartPrunesBootDirectoriesOlderThan14DaysButKeepsTheCurrentAndThePrevious(): void
    {
        $root = sys_get_temp_dir() . '/baander-log-boots-' . (random_bytes(8) |> bin2hex(...));
        $days = static fn(int $age): int => time() - $age * 86400;
        $boots = [
            // The current and previous boots stay whatever their age.
            'web/current' => $days(30), 'web/previous' => $days(31), 'web/older' => $days(32),
            'control/current' => $days(1), 'control/previous' => $days(2), 'control/stale' => $days(15), 'control/recent' => $days(10),
            'control/appended' => $days(20),
            'execution/only' => $days(40),
        ];
        try {
            foreach ($boots as $boot => $modified) {
                mkdir($root . '/' . $boot, 0700, true);
                file_put_contents($root . '/' . $boot . '/prod.log', "{}\n");
                touch($root . '/' . $boot . '/prod.log', $modified);
                touch($root . '/' . $boot, $modified);
            }
            // A file appended three days ago makes its boot recent though the directory is old.
            touch($root . '/control/appended/prod.log', $days(3));
            mkdir($root . '/outside');
            touch($root . '/outside', $days(60));
            symlink($root . '/outside', $root . '/execution/linked');

            $process = new Process([PHP_BINARY, '-r', LogCollection::pruneBootsProgram(), $root]);
            $process->mustRun();

            self::assertSame(['current', 'previous'], self::boots($root, 'web'));
            self::assertSame(['appended', 'current', 'previous', 'recent'], self::boots($root, 'control'));
            self::assertSame(['linked', 'only'], self::boots($root, 'execution'));
            self::assertFileExists($root . '/outside/.');
        } finally {
            new Process(['rm', '-rf', '--', $root])->run();
        }
    }

    /** @return list<string> */
    private static function boots(string $root, string $service): array
    {
        $names = array_map(basename(...), glob($root . '/' . $service . '/*') ?: []);
        sort($names);

        return $names;
    }

    public function testCollectorRejectsImplicitRemoteSocketMapping(): void
    {
        $this->expectException(\RuntimeException::class);
        LogCollection::configuration('worker-node', new Target('/usr/bin/docker', 'tcp://127.0.0.1:2376', 'captured-daemon', null, null), []);
    }

}
