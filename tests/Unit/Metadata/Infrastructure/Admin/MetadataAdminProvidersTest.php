<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Admin;

use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Infrastructure\Admin\MetadataAdminService;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MetadataAdminProvidersTest extends TestCase
{
    public function testConfigurationAndEnabledAreIndependentAndAreReadWithoutDatabaseOrNetwork(): void
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): bool => $key !== MetadataProvider::Tmdb->settingKey());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getConnection');
        $service = new MetadataAdminService(
            $manager,
            new MetadataProviderSwitches($settings),
            discogsToken: '   ',
            spotifyClientId: 'client',
            tmdbBearerToken: 'configured',
            tvdbApiKey: 'configured',
        );

        $providers = array_column($service->getProviders(), null, 'id');

        self::assertCount(9, $providers);
        self::assertFalse($providers['discogs']['configured']);
        self::assertTrue($providers['discogs']['enabled']);
        self::assertFalse($providers['spotify']['configured']);
        self::assertFalse($providers['tmdb']['enabled']);
        self::assertTrue($providers['tmdb']['configured']);
        self::assertTrue($providers['tvdb']['configured']);
        self::assertTrue($providers['local']['configured']);
        self::assertSame('This product uses the TMDB API but is not endorsed or certified by TMDB.', $providers['tmdb']['attributionText']);
        self::assertSame('https://thetvdb.com/', $providers['tvdb']['attributionUrl']);
    }
}
