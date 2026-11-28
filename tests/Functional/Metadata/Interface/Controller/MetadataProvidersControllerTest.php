<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Interface\Controller;

use App\Metadata\Application\Settings\MetadataProvider;
use App\Shared\Application\Service\SystemSettings;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MetadataProvidersControllerTest extends TestCase
{
    public function testProvidersListReportsEveryProviderAndAttributionWithoutCredentials(): void
    {
        $admin = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $admin);
        $providers = $this->assertJsonResponse($response, 200)['data'];

        self::assertSame(array_map(static fn (MetadataProvider $provider): string => $provider->value, MetadataProvider::cases()), array_column($providers, 'id'));
        foreach ($providers as $provider) {
            self::assertSame(['id', 'name', 'enabled', 'configured', 'attributionText', 'attributionUrl'], array_keys($provider));
            self::assertTrue($provider['enabled']);
            self::assertIsBool($provider['configured']);
            self::assertNotSame('', $provider['attributionText']);
            self::assertStringStartsWith('https://', $provider['attributionUrl']);
        }
    }

    public function testAnAdminCanSwitchAProviderWhileGenericSettingsKeepTheirAccessRule(): void
    {
        $admin = $this->createAdminUser();
        $response = $this->authenticatedRequest('PATCH', '/api/admin/metadata/providers/discogs', $admin, ['enabled' => false]);
        $provider = $this->assertJsonResponse($response, 200)['data'];

        self::assertSame('discogs', $provider['id']);
        self::assertFalse($provider['enabled']);
        self::assertFalse(static::getContainer()->get(SystemSettings::class)->get('metadata.provider.discogs.enabled'));
        self::assertSame(403, $this->authenticatedRequest('PATCH', '/api/admin/settings', $admin, [
            'settings' => ['metadata.provider.discogs.enabled' => true],
        ])->getStatusCode());
    }

    public function testAMemberCannotListOrSwitchProviders(): void
    {
        $member = $this->createTestUser();

        self::assertSame(403, $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $member)->getStatusCode());
        self::assertSame(403, $this->authenticatedRequest('PATCH', '/api/admin/metadata/providers/discogs', $member, ['enabled' => false])->getStatusCode());
        self::assertTrue(static::getContainer()->get(SystemSettings::class)->get('metadata.provider.discogs.enabled'));
    }

    public function testAnUnknownProviderDoesNotCreateOrChangeSettings(): void
    {
        $admin = $this->createAdminUser();
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM system_settings ORDER BY key');

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/admin/metadata/providers/auto_sync', $admin, ['enabled' => false]), 422);

        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM system_settings ORDER BY key'));
    }

    public function testTheSwitchRequiresABoolean(): void
    {
        $admin = $this->createAdminUser();

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/admin/metadata/providers/discogs', $admin, ['enabled' => 'false']), 422);
        self::assertTrue(static::getContainer()->get(SystemSettings::class)->get('metadata.provider.discogs.enabled'));
    }

    public function testProviderCommandAndGenericSettingsCommandChangeTheSameSwitchAsTheRoute(): void
    {
        $admin = $this->createAdminUser();
        $application = new Application($this->client->getKernel());
        $switch = new CommandTester($application->find('app:metadata:provider:set'));
        self::assertSame(Command::SUCCESS, $switch->execute(['provider' => 'discogs', 'enabled' => 'false', '--json' => true]));
        $state = json_decode($switch->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $providers = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/metadata/providers', $admin), 200)['data'];
        self::assertSame($state, array_values(array_filter($providers, static fn (array $provider): bool => $provider['id'] === 'discogs'))[0]);
        self::assertFalse($state['enabled']);

        $settings = new CommandTester($application->find('app:settings:set'));
        self::assertSame(Command::SUCCESS, $settings->execute(['key' => 'metadata.provider.discogs.enabled', 'value' => 'true']));
        $providers = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/metadata/providers', $admin), 200)['data'];
        self::assertTrue(array_values(array_filter($providers, static fn (array $provider): bool => $provider['id'] === 'discogs'))[0]['enabled']);
        $response = $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/admin/metadata/providers/discogs', $admin, ['enabled' => true]), 200)['data'];
        self::assertTrue($response['enabled']);

        $list = new CommandTester($application->find('app:metadata:providers'));
        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true]));
        $providers = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/metadata/providers', $admin), 200)['data'];
        self::assertSame($providers, json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }
}
