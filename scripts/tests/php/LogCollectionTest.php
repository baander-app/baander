<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Deployment\LogCollection;
use Baander\Tooling\Docker\Target;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('/run/user/1000/docker.sock', $service['volumes'][0]['source']);
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
        $directory = sys_get_temp_dir() . '/baander-log-credentials-' . bin2hex(random_bytes(8));
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

    public function testCollectorRejectsImplicitRemoteSocketMapping(): void
    {
        $this->expectException(\RuntimeException::class);
        LogCollection::configuration('worker-node', new Target('/usr/bin/docker', 'tcp://127.0.0.1:2376', 'captured-daemon', null, null), []);
    }

}
