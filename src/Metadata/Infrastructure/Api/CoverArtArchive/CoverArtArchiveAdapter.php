<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\CoverArtArchive;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use Baander\HttpClient\PublicHttpClient;
use Baander\HttpClient\ResponseTooLargeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Anti-corruption layer for the Cover Art Archive, which serves the cover images of
 * MusicBrainz releases from archive.org.
 */
final readonly class CoverArtArchiveAdapter
{
    private const string BASE_URL = 'https://coverartarchive.org';
    private const string USER_AGENT = 'Baander/1.0 (https://baander.app; contact@baander.app)';
    /** The archive answers image requests with redirects to archive.org and its download hosts. */
    private const array REDIRECT_DOMAINS = ['coverartarchive.org', 'archive.org'];
    /** Seconds a request, redirects included, may take before the archive counts as unavailable. */
    private const int TIMEOUT_SECONDS = 10;
    /** Client errors that mean "try again later" rather than a refused request. */
    private const array NOT_REJECTIONS = [408, 429];
    /** The largest cover image read; a larger one is an unusable answer. */
    private const int MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private LoggerInterface $logger,
        private JsonEncoder $jsonEncoder,
        private PublicHttpClient $httpClient,
        private MetadataProviderSwitches $switches,
    ) {
    }

    /**
     * Fetch a release's front cover at most 1200 pixels wide, following the archive's
     * redirects to the archive.org hosts that serve the file.
     *
     * The rendition caps the size of the image: originals are often large scans.
     * Never throws: returns null when the release has no front cover (404),
     * MusicBrainzRejected for another client error such as 400 for an invalid release ID, and
     * MusicBrainzUnavailable when the archive gave no usable answer, an image over 10 MiB included.
     * The image's type is the one the archive served; only its bytes can confirm it.
     */
    public function fetchFrontCover(string $releaseId): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        if (!$this->switches->enabled(MetadataProvider::CoverArtArchive)) {
            return new MusicBrainzDisabled('coverartarchive');
        }
        $endpoint = '/release/' . rawurlencode($releaseId) . '/front-1200';
        $response = null;
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $endpoint, ['coverartarchive.org'], [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'image/*'],
                'timeout' => self::TIMEOUT_SECONDS,
            ], self::REDIRECT_DOMAINS);
            $status = $response->getStatusCode();
            $bytes = $status === 200 ? $this->httpClient->content($response, self::MAX_IMAGE_BYTES) : '';
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (ResponseTooLargeException) {
            return $this->unavailable($endpoint, sprintf('the image is larger than %d bytes', self::MAX_IMAGE_BYTES));
        } catch (\Throwable) {
            return $this->unavailable($endpoint, 'the request failed');
        } finally {
            $response?->cancel();
        }

        return match (true) {
            $status === 200 && $bytes !== '' => new MusicBrainzCoverImage($bytes, self::mediaType($type)),
            $status === 200 => $this->unavailable($endpoint, 'the image is empty'),
            $status === 404 => null,
            $status >= 400 && $status < 500 && !in_array($status, self::NOT_REJECTIONS, true) => $this->rejected($endpoint, $status),
            default => $this->unavailable($endpoint, sprintf('HTTP %d', $status)),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getReleaseCovers(string $releaseGroupId): array
    {
        $endpoint = '/release-group/' . $releaseGroupId;
        $data = $this->request($endpoint);

        if (empty($data)) {
            $endpoint = '/release/' . $releaseGroupId;
            $data = $this->request($endpoint);
        }

        return $this->mapCoversData($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getReleaseCover(string $releaseId): array
    {
        $endpoint = '/release/' . $releaseId;
        $data = $this->request($endpoint);

        return $this->mapCoversData($data);
    }

    public function getFrontCoverUrl(string $mbid): ?string
    {
        $endpoint = '/release-group/' . $mbid;
        $data = $this->request($endpoint);

        if (!empty($data)) {
            $frontCover = $this->findFirstCoverByType($data, 'front');
            if ($frontCover !== null) {
                return $frontCover['image'];
            }

            if (!empty($data[0] ?? null)) {
                return $data[0]['image'];
            }
        }

        $endpoint = '/release/' . $mbid;
        $data = $this->request($endpoint);

        if (!empty($data)) {
            $frontCover = $this->findFirstCoverByType($data, 'front');
            if ($frontCover !== null) {
                return $frontCover['image'];
            }

            if (!empty($data[0] ?? null)) {
                return $data[0]['image'];
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function request(string $endpoint): array
    {
        $this->switches->requireEnabled(MetadataProvider::CoverArtArchive);
        $response = null;
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $endpoint, ['coverartarchive.org'], [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'], 'timeout' => self::TIMEOUT_SECONDS,
            ], self::REDIRECT_DOMAINS);
            $body = $this->httpClient->content($response);
        } catch (\Throwable) {
            $this->logger->warning('CoverArtArchive request failed', ['service' => 'coverartarchive']);

            return [];
        } finally {
            $response?->cancel();
        }
        try {
            $data = $this->jsonEncoder->decode($body, 'json');
        } catch (NotEncodableValueException) {
            $this->logger->warning('CoverArtArchive JSON decode failed', ['service' => 'coverartarchive']);

            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function mapCoversData(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $mapped = [];

        foreach ($data as $image) {
            $mappedItem = [
                'image' => $image['image'] ?? '',
                'thumbnail' => $image['thumbnails']['small'] ?? '',
                'small' => $image['thumbnails']['large'] ?? '',
                'large' => $image['thumbnails']['500'] ?? '',
                'types' => [],
                'approved' => $image['approved'] ?? false,
            ];

            if (isset($image['types']) && is_array($image['types'])) {
                $mappedItem['types'] = $image['types'];
            }

            $mapped[] = $mappedItem;
        }

        return $mapped;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    private function findFirstCoverByType(array $data, string $type): ?array
    {
        foreach ($data as $image) {
            if (
                isset($image['types']) &&
                is_array($image['types']) &&
                in_array($type, $image['types'], true)
            ) {
                return [
                    'image' => $image['image'] ?? '',
                ];
            }
        }

        return null;
    }

    /** The media type without parameters, such as "image/jpeg"; "application/octet-stream" when none was sent. */
    private static function mediaType(string $contentType): string
    {
        return (explode(';', $contentType)[0] |> trim(...) |> strtolower(...)) ?: 'application/octet-stream';
    }

    private function rejected(string $endpoint, int $status): MusicBrainzRejected
    {
        $this->logger->warning('CoverArtArchive rejected the request', ['service' => 'coverartarchive', 'endpoint' => $endpoint, 'status' => $status]);

        return new MusicBrainzRejected($status);
    }

    private function unavailable(string $endpoint, string $reason): MusicBrainzUnavailable
    {
        $this->logger->warning('CoverArtArchive request failed', ['service' => 'coverartarchive', 'endpoint' => $endpoint, 'error' => $reason]);

        return new MusicBrainzUnavailable($reason);
    }
}
