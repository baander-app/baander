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

    public function testScanLibraryCommandRoutesToSwooleTaskTransport(): void
    {
        $config = $this->loadMessengerConfig();

        $this->assertArrayHasKey('routing', $config['framework']['messenger']);
        $routing = $config['framework']['messenger']['routing'];

        // Verify ScanLibraryCommand is routed to swoole_task transport
        $this->assertArrayHasKey(
            'App\\Library\\Application\\Command\\ScanLibraryCommand',
            $routing
        );

        $this->assertSame(
            'swoole_task',
            $routing['App\\Library\\Application\\Command\\ScanLibraryCommand'],
            'ScanLibraryCommand should be routed to swoole_task transport'
        );
    }

    public function testAsyncAndSwooleTaskAreDifferentTransports(): void
    {
        $config = $this->loadMessengerConfig();

        $this->assertArrayHasKey('transports', $config['framework']['messenger']);
        $transports = $config['framework']['messenger']['transports'];

        // Verify both transports exist and are configured differently
        $this->assertArrayHasKey('async', $transports);
        $this->assertArrayHasKey('swoole_task', $transports);

        // They must use different DSNs/implementations
        $this->assertNotSame(
            $transports['async']['dsn'] ?? '',
            $transports['swoole_task']['dsn'] ?? '',
            'async and swoole_task must be different transports to avoid nesting'
        );

        // swoole_task should use swoole://task
        $this->assertStringContainsString('swoole', $transports['swoole_task']['dsn']);

        // async should use Redis ( MESSENGER_TRANSPORT_DNS env var)
        $this->assertStringContainsString('%env(MESSENGER_TRANSPORT_DSN)%', $transports['async']['dsn']);
    }

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
