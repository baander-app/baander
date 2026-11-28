<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\MusicBrainz;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MusicBrainzProviderSwitchTest extends TestCase
{
    private MockHttpClient $http;

    public function testDisabledMusicBrainzAnswersEveryLookupWithoutAProviderRequest(): void
    {
        [$musicBrainz] = $this->adapters(ProviderSwitches::withDisabled(MetadataProvider::MusicBrainz));
        self::assertFalse($musicBrainz->isEnabled());

        foreach ([
            $musicBrainz->findReleases('Album', 'Artist'),
            $musicBrainz->fetchRelease('release'),
            $musicBrainz->findRecordings('Song', 'Artist'),
            $musicBrainz->fetchRecording('recording'),
            $musicBrainz->findArtists('Artist'),
            $musicBrainz->fetchArtist('artist'),
            $musicBrainz->fetchFrontCover('release'),
        ] as $answer) {
            self::assertInstanceOf(MusicBrainzDisabled::class, $answer);
            self::assertSame('musicbrainz', $answer->provider);
        }
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testLegacyMusicBrainzMethodsReportDisabledInsteadOfAnEmptyResult(): void
    {
        [$musicBrainz] = $this->adapters(ProviderSwitches::withDisabled(MetadataProvider::MusicBrainz));
        foreach ([
            fn () => $musicBrainz->searchArtist('Artist'),
            fn () => $musicBrainz->searchReleaseGroup('Album'),
            fn () => $musicBrainz->searchRecording('Song'),
            fn () => $musicBrainz->lookupArtist('artist'),
            fn () => $musicBrainz->lookupReleaseGroup('release-group'),
        ] as $lookup) {
            try {
                $lookup();
                self::fail('The switched-off provider must report disabled.');
            } catch (MetadataProviderDisabled $disabled) {
                self::assertSame(MetadataProvider::MusicBrainz, $disabled->provider);
                self::assertSame('provider_disabled', $disabled->details['reason']);
            }
        }
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testDisabledCoverArtIsDistinctFromAMissingCoverAndMakesNoRequest(): void
    {
        [$musicBrainz, $archive] = $this->adapters(ProviderSwitches::withDisabled(MetadataProvider::CoverArtArchive));
        self::assertTrue($musicBrainz->isEnabled());
        foreach ([$musicBrainz->fetchFrontCover('release'), $archive->fetchFrontCover('release')] as $cover) {
            self::assertInstanceOf(MusicBrainzDisabled::class, $cover);
            self::assertSame('coverartarchive', $cover->provider);
        }
        foreach ([
            fn () => $archive->getReleaseCovers('release-group'),
            fn () => $archive->getReleaseCover('release'),
            fn () => $archive->getFrontCoverUrl('release-group'),
        ] as $lookup) {
            try {
                $lookup();
                self::fail('The switched-off cover provider must report disabled.');
            } catch (MetadataProviderDisabled $disabled) {
                self::assertSame(MetadataProvider::CoverArtArchive, $disabled->provider);
            }
        }
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testASwitchChangedWhileTakingARateLimitTurnPreventsTheRequest(): void
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $reads = 0;
        $settings->method('get')->willReturnCallback(static function () use (&$reads): bool {
            return ++$reads === 1;
        });
        [$musicBrainz] = $this->adapters(new MetadataProviderSwitches($settings));

        self::assertInstanceOf(MusicBrainzDisabled::class, $musicBrainz->fetchArtist('artist'));
        self::assertSame(2, $reads);
        self::assertSame(0, $this->http->getRequestsCount());
    }

    /** @return iterable<string, array{bool}> */
    public static function limiterFailures(): iterable
    {
        yield 'no bounded turn' => [true];
        yield 'limiter unavailable' => [false];
    }

    #[DataProvider('limiterFailures')]
    public function testADisabledSwitchAtTheEndOfARateLimitWaitStillAnswersDisabled(bool $boundedWait): void
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $reads = 0;
        $settings->method('get')->willReturnCallback(static function () use (&$reads): bool {
            return ++$reads === 1;
        });
        $failure = $boundedWait
            ? new MaxWaitDurationExceededException('No turn is available.', new RateLimit(0, new \DateTimeImmutable('+1 hour'), false, 1))
            : new RateLimiterUnavailableException();
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('reserve')->willThrowException($failure);
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);
        [$musicBrainz] = $this->adapters(new MetadataProviderSwitches($settings), $factory);

        self::assertInstanceOf(MusicBrainzDisabled::class, $musicBrainz->fetchArtist('artist'));
        self::assertSame(2, $reads);
        self::assertSame(0, $this->http->getRequestsCount());
    }

    /** @return array{MusicBrainzAdapter, CoverArtArchiveAdapter} */
    private function adapters(MetadataProviderSwitches $switches, ?RateLimiterFactoryInterface $limiter = null): array
    {
        $this->http = new MockHttpClient(static fn () => throw new \LogicException('No provider request is expected.'));
        $http = new PublicHttpClient($this->http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));
        $archive = new CoverArtArchiveAdapter(new NullLogger(), new JsonEncoder(), $http, $switches);
        $musicBrainz = new MusicBrainzAdapter(
            new NullLogger(),
            new JsonEncoder(),
            $http,
            $limiter ?? new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage()),
            $archive,
            $switches,
        );

        return [$musicBrainz, $archive];
    }
}
