<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\AuthHandler as BaseAuthHandler;

class MockAuthHandler extends BaseAuthHandler
{
    public function __construct()
    {
        // Don't call parent constructor
    }

    public function getToken(): string
    {
        return 'mock-api-token';
    }

    public function getSession(string $token): array
    {
        return [
            'session' => [
                'name' => 'mockuser',
                'key' => 'mock-session-key',
            ],
        ];
    }
}
