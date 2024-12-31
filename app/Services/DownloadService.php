<?php

namespace App\Services;

class DownloadService
{
    public function __construct(private readonly GuzzleService $guzzleService)
    {
    }

    public function downloadUrl(string $url): mixed
    {
        $response = $this->guzzleService->getAsync($url);

        return $response->wait();
    }
}