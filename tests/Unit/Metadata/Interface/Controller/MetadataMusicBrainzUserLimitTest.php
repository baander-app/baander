<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Interface\Controller\MetadataBrowseController;
use App\Metadata\Interface\Controller\MetadataSearchController;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Yaml\Yaml;

/** The open metadata routes spend a member's own MusicBrainz allowance before the shared budget. */
final class MetadataMusicBrainzUserLimitTest extends TestCase
{
    private const string RELEASE_ID = 'f3c3a3b8-5d1e-4c55-9a0e-7a51f4c2d001';
    private const string MEMBER_ID = '0192f3a1-7b2c-7d4e-8f90-a1b2c3d4e501';
    private const string OTHER_ID = '0192f3a1-7b2c-7d4e-8f90-a1b2c3d4e502';

    /** @var list<string> */
    private array $musicBrainzUrls = [];
    private MusicBrainzAdapter $musicBrainz;
    private MetadataSearchController $search;
    private MetadataBrowseController $browse;

    protected function setUp(): void
    {
        $raw = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (parse_url($url, PHP_URL_HOST) === 'musicbrainz.org') {
                $this->musicBrainzUrls[] = $url;
            }

            return new MockResponse(json_encode([
                'id' => self::RELEASE_ID, 'title' => 'F-1 Trillion', 'name' => 'Post Malone',
                'count' => 0, 'pagination' => ['items' => 0], 'results' => [],
            ], JSON_THROW_ON_ERROR));
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $this->musicBrainz = new MusicBrainzAdapter(
            $logger,
            $encoder,
            $client,
            self::shippedLimiter('musicbrainz'),
            new CoverArtArchiveAdapter($logger, $encoder, $client),
        );
        $lastFm = new LastFmAdapter('local-test-token', $logger, $encoder, $client);
        $userLimiter = self::shippedLimiter('musicbrainz_user');
        $this->search = new MetadataSearchController(
            $this->musicBrainz,
            new DiscogsAdapter('local-test-token', $logger, $encoder, $client),
            $lastFm,
            $userLimiter,
        );
        $this->browse = new MetadataBrowseController($this->musicBrainz, $lastFm, $userLimiter);
    }

    public function testAMemberCallingASearchRouteInALoopGetsTooManyRequestsAfterTheFirstCallInTenSeconds(): void
    {
        $member = self::member(self::MEMBER_ID, 'member@baander.app');

        self::assertSame(200, $this->search->searchArtist(new Request(['q' => 'Post Malone']), $member)->getStatusCode());
        foreach (['searchArtist', 'searchAlbum', 'searchSong'] as $action) {
            $this->assertTooManyRequests(fn() => $this->search->{$action}(new Request(['q' => 'Post Malone']), $member));
        }
        $this->assertTooManyRequests(fn() => $this->browse->artist(self::RELEASE_ID, $member));
        $this->assertTooManyRequests(fn() => $this->browse->releaseGroup(self::RELEASE_ID, $member));

        self::assertCount(1, $this->musicBrainzUrls, 'A refused call spends nothing of the shared budget.');
    }

    public function testOneMemberUnderTwoEmailAddressesSharesOneAllowance(): void
    {
        $before = self::member(self::MEMBER_ID, 'member@baander.app');
        $after = self::member(self::MEMBER_ID, 'renamed@baander.app');

        self::assertSame(200, $this->search->searchArtist(new Request(['q' => 'Post Malone']), $before)->getStatusCode());
        $this->assertTooManyRequests(fn() => $this->search->searchArtist(new Request(['q' => 'Post Malone']), $after));
        $this->assertTooManyRequests(fn() => $this->browse->artist(self::RELEASE_ID, $after));

        self::assertCount(1, $this->musicBrainzUrls);
    }

    public function testTheLimitIsPerMemberAndOnlyForMusicBrainz(): void
    {
        $member = self::member(self::MEMBER_ID, 'member@baander.app');
        $other = self::member(self::OTHER_ID, 'other@baander.app');

        self::assertSame(200, $this->browse->releaseGroup(self::RELEASE_ID, $member)->getStatusCode());
        self::assertSame(200, $this->search->searchAlbum(new Request(['q' => 'F-1 Trillion', 'source' => 'discogs']), $member)->getStatusCode());
        self::assertSame(200, $this->search->searchArtist(new Request(['q' => 'Post Malone', 'source' => 'lastfm']), $member)->getStatusCode());
        self::assertSame(200, $this->browse->artist(self::RELEASE_ID, $other)->getStatusCode());
    }

    public function testAnAdminLookupStillGetsATurnWithinTheWaitLimitWhileAMemberLoops(): void
    {
        $member = self::member(self::MEMBER_ID, 'member@baander.app');
        $this->search->searchSong(new Request(['q' => 'Pour Me a Drink']), $member);
        for ($call = 0; $call < 5; ++$call) {
            $this->assertTooManyRequests(fn() => $this->search->searchSong(new Request(['q' => 'Pour Me a Drink']), $member));
        }

        $release = $this->musicBrainz->fetchRelease(self::RELEASE_ID, MusicBrainzSlotWait::Bounded);

        self::assertInstanceOf(MusicBrainzRelease::class, $release);
        self::assertCount(2, $this->musicBrainzUrls);
    }

    private function assertTooManyRequests(\Closure $call): void
    {
        try {
            $call();
            self::fail('The member has spent this allowance.');
        } catch (TooManyRequestsHttpException $refusal) {
            self::assertSame(429, $refusal->getStatusCode());
            // The limiter rounds the end of the ten-second window up to a whole second.
            self::assertGreaterThanOrEqual(1, $refusal->getHeaders()['Retry-After']);
            self::assertLessThanOrEqual(11, $refusal->getHeaders()['Retry-After']);
        }
    }

    /** A signed-in member as the firewall supplies one. */
    private static function member(string $id, string $email): SecurityUser
    {
        return new SecurityUser($id, $email, 'stored-password-hash');
    }

    /** A limiter with the configuration the application ships, on in-memory storage. */
    private static function shippedLimiter(string $name): RateLimiterFactory
    {
        $config = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/framework.yaml')['framework']['rate_limiter'][$name];

        return new RateLimiterFactory(['id' => $name, ...$config], new InMemoryStorage());
    }
}
