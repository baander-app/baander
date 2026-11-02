<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Interface\Security\SignedStreamRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamSigningOriginTest extends KernelTestCase
{
    public function testConfiguredSignerUsesTheApplicationOriginWithItsSchemeAndPort(): void
    {
        $savedEnv = $_ENV;
        $savedServer = $_SERVER;
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://media.baander.app:8443';
        $_ENV['APP_DOMAIN'] = $_SERVER['APP_DOMAIN'] = 'media.baander.app';

        try {
            self::bootKernel();
            $signer = self::getContainer()->get(HmacStreamAuthAdapter::class);
            self::assertInstanceOf(HmacStreamAuthAdapter::class, $signer);
            $path = '/api/transcode/job/master.m3u8?generation=3';
            $signed = $signer->signUrl($path, 120);
            self::assertStringStartsWith('https://media.baander.app:8443' . $path . '&sig=', $signed['url']);
            $request = Request::create($signed['url']);
            self::assertSame('/api/transcode/job/master.m3u8', $request->getPathInfo());
            self::assertTrue(new SignedStreamRequest($signer)->isValid($request));
        } finally {
            self::ensureKernelShutdown();
            $_ENV = $savedEnv;
            $_SERVER = $savedServer;
        }
    }
}
