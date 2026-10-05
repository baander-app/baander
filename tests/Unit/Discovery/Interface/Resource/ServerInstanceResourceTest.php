<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Interface\Resource\ServerInstanceResource;
use PHPUnit\Framework\TestCase;

final class ServerInstanceResourceTest extends TestCase
{
    public function testServerMetadataExcludesCredential(): void
    {
        $server = ServerInstance::create('https://music.baander.app', 'Home Server', '1.2.3', 'private-server-key');

        $resource = ServerInstanceResource::from($server);

        self::assertSame('https://music.baander.app', $resource['serverUrl']);
        self::assertArrayNotHasKey('apiKey', $resource);
        self::assertStringNotContainsString('private-server-key', json_encode($resource, JSON_THROW_ON_ERROR));
    }
}
