<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Infrastructure\Video\VideoMetadataProviders;
use App\Metadata\Infrastructure\Video\VideoProviderAdapter;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use PHPUnit\Framework\TestCase;

final class VideoMetadataProvidersTest extends TestCase
{
    public function testDefaultOrderIncludesEveryProviderThatServesTheKind(): void
    {
        $providers = new VideoMetadataProviders(ProviderSwitches::allEnabled(), []);

        self::assertSame([VideoProviderId::Tvdb, VideoProviderId::Tmdb, VideoProviderId::Local], $providers->providers(VideoKind::Series));
        self::assertSame([VideoProviderId::Tmdb, VideoProviderId::Local], $providers->providers(VideoKind::Movie));
    }

    public function testConfigurationAndSwitchAreCheckedBeforeSearchingOrLookingUp(): void
    {
        $request = new VideoRequest(VideoKind::Series, 'Show');
        $adapter = $this->createMock(VideoProviderAdapter::class);
        $adapter->method('provider')->willReturn(VideoProviderId::Tmdb);
        $adapter->method('isConfigured')->willReturn(false);
        $adapter->expects(self::never())->method('search');
        $adapter->expects(self::never())->method('identify');

        $disabled = new VideoMetadataProviders(ProviderSwitches::withDisabled(MetadataProvider::Tmdb), [$adapter]);
        self::assertSame([], $disabled->usableProviders(VideoKind::Series));
        self::assertSame(VideoProviderProblem::Disabled, $disabled->search(VideoProviderId::Tmdb, $request));
        self::assertSame(VideoProviderProblem::Disabled, $disabled->identify(VideoProviderId::Tmdb, $request));

        $unconfigured = new VideoMetadataProviders(ProviderSwitches::allEnabled(), [$adapter]);
        self::assertSame([], $unconfigured->usableProviders(VideoKind::Series));
        self::assertSame(VideoProviderProblem::NotConfigured, $unconfigured->search(VideoProviderId::Tmdb, $request));
        self::assertSame(VideoProviderProblem::NotConfigured, $unconfigured->identify(VideoProviderId::Tmdb, $request));
    }

    public function testListingUsableProvidersNeverSearchesOrFetchesMetadata(): void
    {
        $adapter = $this->createMock(VideoProviderAdapter::class);
        $adapter->method('provider')->willReturn(VideoProviderId::Tmdb);
        $adapter->method('isConfigured')->willReturn(true);
        $adapter->expects(self::never())->method('search');
        $adapter->expects(self::never())->method('identify');

        $providers = new VideoMetadataProviders(ProviderSwitches::allEnabled(), [$adapter]);
        self::assertSame([VideoProviderId::Tmdb], $providers->usableProviders(VideoKind::Series));
    }
}
