<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Server registration through POST /api/discovery/register and through app:discovery:register
 * reaches one use case: the key is created there, never printed, and a repeat keeps the public ID.
 */
final class DiscoveryRegisterCliParityTest extends TestCase
{
    private const string URL = 'https://discovery-parity.baander.app';

    public function testBothPathsRegisterWithoutAKeyAndKeepThePublicIdOnRepeat(): void
    {
        $admin = $this->createAdminUser();
        $viaApi = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/discovery/register', $admin, [
            'serverUrl' => self::URL . '/api',
            'name' => 'Home Server',
            'version' => '1.0.0',
        ]), 201, 'data')['data'];

        $first = $this->register(self::URL . '/cli');
        $repeat = $this->register(self::URL . '/cli', '1.1.0');

        self::assertSame(array_keys($viaApi), array_keys($first), 'The CLI prints the API data.');
        self::assertSame($first['publicId'], $repeat['publicId']);
        self::assertSame('1.1.0', $repeat['version']);

        foreach ([self::URL . '/api' => $viaApi, self::URL . '/cli' => $first] as $url => $printed) {
            $server = $this->serverPort()->findByServerUrl($url);
            self::assertNotNull($server);
            self::assertSame(64, strlen($server->getApiKey()));
            self::assertStringNotContainsString($server->getApiKey(), json_encode($printed, JSON_THROW_ON_ERROR));
            self::assertSame($printed['publicId'], $server->getPublicId()->toString());
        }
    }

    public function testBothPathsRejectAMalformedUrl(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/discovery/register', $this->createAdminUser(), [
            'serverUrl' => 'not a url',
            'name' => 'Home Server',
            'version' => '1.0.0',
        ]);
        $this->assertJsonResponse($response, 422);

        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute(['url' => 'not a url', 'name' => 'Home Server', 'version' => '1.0.0']));
        self::assertNull($this->serverPort()->findByServerUrl('not a url'));
    }

    /** @return array<string, mixed> */
    private function register(string $url, string $version = '1.0.0'): array
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['url' => $url, 'name' => 'Home Server', 'version' => $version, '--json' => true]), $tester->getDisplay());

        $printed = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($printed);

        return $printed;
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find('app:discovery:register'));
    }

    private function serverPort(): ServerInstancePortInterface
    {
        return static::getContainer()->get(ServerInstancePortInterface::class);
    }
}
