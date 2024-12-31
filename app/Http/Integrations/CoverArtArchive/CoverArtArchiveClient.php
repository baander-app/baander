<?php

namespace App\Http\Integrations\CoverArtArchive;

use GuzzleHttp\Client;
use App\Http\Integrations\CoverArtArchive\Models\CoverArtResponse;

class CoverArtArchiveClient
{
    public const string BASE_URL = 'https://coverartarchive.org/release/';

    public function __construct(private readonly Client $client)
    {
    }

    public function getCoverArtUrl(string $musicBrainzId): string
    {
        $response = $this->client->getAsync(self::BASE_URL . $musicBrainzId)->wait();

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('API request failed: ' . $response->getReasonPhrase());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $coverArtResponse = CoverArtResponse::fromApiData($data);

        foreach ($coverArtResponse->images as $image) {
            if ($image->front) {
                return $image->image;
            }
        }
    }
}