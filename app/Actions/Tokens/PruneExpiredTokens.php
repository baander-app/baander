<?php

namespace App\Actions\Tokens;

use App\Modules\Auth\AccessTokenService;
use JetBrains\PhpStorm\ArrayShape;

class PruneExpiredTokens
{
    public function __construct(private readonly AccessTokenService $authTokenManager)
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