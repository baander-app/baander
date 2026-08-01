<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\CoverArtArchive;

use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

final readonly class CoverArtArchiveAdapter
{
    private const BASE_URL = 'https://coverartarchive.org';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    )
    {
    }

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

    private function request(string $endpoint): array
    {
        $url = self::BASE_URL . $endpoint;
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Baander/1.0',
                    'Accept: application/json',
                ],
                'ignore_errors' => true,
                'timeout' => 10.0,
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->warning('CoverArtArchive request failed', [
                'service' => 'coverartarchive',
                'endpoint' => $url,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
            return [];
        }

        try {
            $data = $this->jsonEncoder->decode($response, 'json');
        } catch (NotEncodableValueException $e) {
            $this->logger->warning('CoverArtArchive JSON decode failed', [
                'service' => 'coverartarchive',
                'endpoint' => $url,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $data;
    }

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