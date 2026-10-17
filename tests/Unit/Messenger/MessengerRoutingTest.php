<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit test verifying Messenger routing configuration in messenger.yaml.
 *
 * Tests that ExtractAlbumCoverCommand is routed to the 'async' transport
 * to avoid Swoole task-worker nesting issues when dispatched from ScanLibraryHandler.
 */
final class MessengerRoutingTest extends TestCase
{
    private const MESSENGER_CONFIG_PATH = __DIR__ . '/../../../config/packages/messenger.yaml';

    public function testExtractAlbumCoverCommandRoutesToAsyncTransport(): void
    {
        $config = $this->loadMessengerConfig();

        $this->assertArrayHasKey('routing', $config['framework']['messenger']);
        $routing = $config['framework']['messenger']['routing'];

        // Verify ExtractAlbumCoverCommand is routed to async transport
        $this->assertArrayHasKey(
            'App\\Metadata\\Application\\Command\\ExtractAlbumCoverCommand',
            $routing
        );

        $this->assertSame(
            'async',
            $routing['App\\Metadata\\Application\\Command\\ExtractAlbumCoverCommand'],
            'ExtractAlbumCoverCommand must be routed to async transport to avoid Swoole task-worker nesting'
        );
    }

    public function testScanLibraryCommandRoutesToAsyncTransport(): void
    {
        $config = $this->loadMessengerConfig();

        $this->assertArrayHasKey('routing', $config['framework']['messenger']);
        $routing = $config['framework']['messenger']['routing'];

        // Verify ScanLibraryCommand is routed to async transport
        $this->assertArrayHasKey(
            'App\\Library\\Application\\Command\\ScanLibraryCommand',
            $routing
        );

        $this->assertSame(
            'async',
            $routing['App\\Library\\Application\\Command\\ScanLibraryCommand'],
            'ScanLibraryCommand should be routed to async transport'
        );
    }

    public function testOnlyDurableWorkerTransportsAreConfigured(): void
    {
        $transports = $this->loadMessengerConfig()['framework']['messenger']['transports'];
        self::assertArrayNotHasKey('swoole_task', $transports);
        self::assertStringContainsString('%env(MESSENGER_TRANSPORT_DSN)%', $transports['async']['dsn']);
    }

    /** @return array<string, mixed> */
    private function loadMessengerConfig(): array
    {
        $content = file_get_contents(self::MESSENGER_CONFIG_PATH);
        if ($content === false) {
            $this->fail('Cannot load messenger.yaml configuration file');
        }

        $config = Yaml::parse($content);

        if (!isset($config['framework']['messenger'])) {
            $this->fail('messenger.yaml does not contain framework.messenger configuration');
        }

        return $config;
    }
}
