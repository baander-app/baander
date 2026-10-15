<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\TasteDive;

use App\Shared\Infrastructure\Http\PublicHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final readonly class TasteDiveAdapter
{
    private const BASE_URL = 'https://tastedive.com/api/similar';

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly PublicHttpClient $httpClient,
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

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function request(array $params): array
    {
        $params['k'] = $this->apiKey;
        $url = self::BASE_URL . '?' . http_build_query($params);
        $this->logger->debug('TasteDive API request', ['service' => 'tastedive']);
        $response = null;
        try {
            $response = $this->httpClient->request('GET', $url, ['tastedive.com'], [
                'headers' => ['User-Agent' => 'Baander/1.0'], 'timeout' => 10,
            ]);
            if ($response->getStatusCode() >= 300) {
                $this->logger->error('TasteDive API request failed', ['service' => 'tastedive']);

                return [];
            }
            $body = $response->getContent(false);
        } catch (\Throwable) {
            $this->logger->error('TasteDive API request failed', ['service' => 'tastedive']);

            return [];
        } finally {
            $response?->cancel();
        }
        $data = $this->jsonEncoder->decode($body, 'json');
        if (!is_array($data) || isset($data['error'])) {
            $this->logger->error('TasteDive API returned error', ['service' => 'tastedive']);

            return [];
        }

        return $data;
    }
}
