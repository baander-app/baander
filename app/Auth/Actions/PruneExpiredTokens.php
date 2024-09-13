<?php

namespace App\Auth\Actions;

use App\Auth\Services\AuthTokenService;
use JetBrains\PhpStorm\ArrayShape;

class PruneExpiredTokens
{
    public function __construct(private readonly AuthTokenService $authTokenManager)
    {
    }

    #[ArrayShape([
        'status'  => "string",
        'removed' => 'int',
        'context' => 'mixed',
    ])]
    public function run(): array
    {
        $count = $this->authTokenManager->getExpiredTokenCount();

        $res = $this->authTokenManager->pruneExpiredTokens();

        return [
            'status'  => 'success',
            'removed' => $count,
            'context' => $res,
        ];
    }
}