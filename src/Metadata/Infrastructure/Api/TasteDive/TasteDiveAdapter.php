<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\TasteDive;

use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final readonly class TasteDiveAdapter
{
    private const BASE_URL = 'https://tastedive.com/api/similar';

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    /**
     * @return array<int, array{name: string, type: string, wTeaser: string, wUrl: string|null, yUrl: string|null, yID: string|null, score: float}>
     */
    public function getSimilar(string $query, string $type = 'music', int $limit = 20): array
    {
        $data = $this->request([
            'q' => $query,
            'type' => $type,
            'limit' => (string) $limit,
            'info' => '1',
        ]);

        $results = $data['Similar']['Results'] ?? [];

        return array_map(
            static fn(array $item): array => [
                'name' => $item['Name'] ?? '',
                'type' => $item['Type'] ?? '',
                'wTeaser' => $item['wTeaser'] ?? '',
                'wUrl' => $item['wUrl'] ?? null,
                'yUrl' => $item['yUrl'] ?? null,
                'yID' => $item['yID'] ?? null,
                'score' => (float) ($item['score'] ?? 0),
            ],
            $results,
        );
    }

    private function request(array $params): array
    {
        $params['k'] = $this->apiKey;

        $url = self::BASE_URL . '?' . http_build_query($params);

        $this->logger->debug('TasteDive API request', ['service' => 'tastedive', 'endpoint' => $url]);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10.0,
                'user_agent' => 'Baander/1.0',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('TasteDive API request failed', ['service' => 'tastedive', 'endpoint' => $url]);

            return [];
        }

        $data = $this->jsonEncoder->decode($response, 'json');

        if (isset($data['error'])) {
            $this->logger->error('TasteDive API returned error', [
                'service' => 'tastedive',
                'code' => $data['error']['code'] ?? 'unknown',
                'message' => $data['error']['message'] ?? '',
            ]);

            return [];
        }

        return $data;
    }
}
