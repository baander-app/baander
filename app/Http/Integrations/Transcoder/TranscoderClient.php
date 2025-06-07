<?php

namespace App\Http\Integrations\Transcoder;

use GuzzleHttp\Client;

class TranscoderClient
{
    public function __construct(
        protected readonly Client $client,
        protected readonly string $baseUrl,
    )
    {
    }

    public function enqueueProbe(string $path)
    {
        $res = $this->client->postAsync($this->baseUrl . '/probe', [
            'body' => [
                'path' => $path,
            ]
        ]);

        return $res->wait();
    }
}