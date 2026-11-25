<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\CoverArtArchive;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class CoverArtArchiveAdapterTest extends TestCase
{
    private const string RELEASE_ID = 'f3c3a3b8-5d1e-4c55-9a0e-7a51f4c2d001';

    /** @var list<string> */
    private array $urls = [];

    public function testFrontCoverFollowsTheRedirectsToTheArchiveAndReturnsTheImage(): void
    {
        $download = 'https://archive.org/download/mbid-' . self::RELEASE_ID . '/mbid-' . self::RELEASE_ID . '-1_thumb1200.jpg';
        $mirror = 'https://ia800502.us.archive.org/1/items/mbid-' . self::RELEASE_ID . '/mbid-' . self::RELEASE_ID . '-1_thumb1200.jpg';

        $cover = $this->adapter(
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location' => $download]]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => $mirror]]),
            new MockResponse('jpeg-bytes', ['response_headers' => ['Content-Type' => 'Image/JPEG; charset=binary']]),
        )->fetchFrontCover(self::RELEASE_ID);

        self::assertEquals(new MusicBrainzCoverImage('jpeg-bytes', 'image/jpeg'), $cover);
        self::assertSame([
            'https://coverartarchive.org/release/' . self::RELEASE_ID . '/front-1200',
            $download,
            $mirror,
        ], $this->urls);
    }

    public function testAFrontCoverOverTenMebibytesIsUnavailable(): void
    {
        $cover = $this->adapter(
            new MockResponse(str_repeat('x', 10 * 1024 * 1024 + 1), ['response_headers' => ['Content-Type' => 'image/jpeg']]),
        )->fetchFrontCover(self::RELEASE_ID);

        self::assertInstanceOf(MusicBrainzUnavailable::class, $cover);
    }

    public function testAReleaseWithoutAFrontCoverHasNone(): void
    {
        self::assertNull($this->adapter(new MockResponse('Not Found', ['http_code' => 404]))->fetchFrontCover(self::RELEASE_ID));
    }

    #[TestWith([503])]
    #[TestWith([429])]
    #[TestWith([500])]
    public function testServerErrorsAreUnavailable(int $status): void
    {
        self::assertInstanceOf(
            MusicBrainzUnavailable::class,
            $this->adapter(new MockResponse('', ['http_code' => $status]))->fetchFrontCover(self::RELEASE_ID),
        );
    }

    public function testAnInvalidReleaseIdIsRejected(): void
    {
        $rejected = $this->adapter(new MockResponse('', ['http_code' => 400]))->fetchFrontCover('not-a-uuid');

        self::assertEquals(new MusicBrainzRejected(400), $rejected);
    }

    public function testARedirectOutsideTheArchiveIsUnavailable(): void
    {
        $cover = $this->adapter(
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location' => 'https://images.baander.app/cover.jpg']]),
        )->fetchFrontCover(self::RELEASE_ID);

        self::assertInstanceOf(MusicBrainzUnavailable::class, $cover);
        self::assertCount(1, $this->urls, 'The redirect is not followed.');
    }

    private function adapter(MockResponse ...$responses): CoverArtArchiveAdapter
    {
        $raw = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->urls[] = $url;

            return array_shift($responses) ?? throw new \LogicException('No response is queued for ' . $url);
        });

        return new CoverArtArchiveAdapter(
            new NullLogger(),
            new JsonEncoder(),
            new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1'])),
        );
    }
}
