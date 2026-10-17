<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Interface\Resource\PairingSessionResource;
use PHPUnit\Framework\TestCase;

final class PairingSessionResourceTest extends TestCase
{
    public function testPairingOutputNamesTheSessionAndServerByPublicIdOnly(): void
    {
        $server = ServerInstance::create('https://music.baander.app', 'Home Server', '1.2.3', 'private-server-key');
        $session = PairingSession::create($server->getId(), $server->getPublicId(), $server->getServerUrl(), $server->getName(), AuthenticationMethod::ServerCode);

        $output = PairingSessionResource::from($session);

        self::assertSame($session->getPublicId()->toString(), $output['publicId']);
        self::assertSame($server->getPublicId()->toString(), $output['serverPublicId']);
        self::assertArrayNotHasKey('uuid', $output);
        $json = json_encode($output, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($session->getId()->toString(), $json);
        self::assertStringNotContainsString($server->getId()->toString(), $json);
        self::assertStringNotContainsString('private-server-key', $json);
    }
}
