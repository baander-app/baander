<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;

class GuzzleService
{
    private static ?Client $client = null;

    public function getClient(): Client
    {
        return static::$client ?? new Client([
            'timeout'     => 10.0,
            'http_errors' => false,
        ]);
    }

    public function getAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->getClient()->getAsync($uri, $options);
    }

    public function postAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->getClient()->postAsync($uri, $options);
    }
}