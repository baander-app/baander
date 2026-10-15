<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\CoverArtArchive;

use App\Shared\Infrastructure\Http\PublicHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

final readonly class CoverArtArchiveAdapter
{
    private const BASE_URL = 'https://coverartarchive.org';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly PublicHttpClient $httpClient,
    )
    {
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
        $response = null;
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $endpoint, ['coverartarchive.org'], [
                'headers' => ['User-Agent' => 'Baander/1.0', 'Accept' => 'application/json'], 'timeout' => 10,
            ], ['coverartarchive.org', 'archive.org']);
            $body = $response->getContent(false);
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
}