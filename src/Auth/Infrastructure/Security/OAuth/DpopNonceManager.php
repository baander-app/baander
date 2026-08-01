<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DpopNonceManager
{
    public function __construct(
        private readonly RedisClientFactory $redisClientFactory,
        private readonly int $nonceTtlSeconds = 300,
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
    }

    public function generateNonce(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function storeNonce(string $nonce): void
    {
        try {
            $this->redisClientFactory->borrow(fn(\Redis $redis) => $redis->setex('dpop:nonce:' . $nonce, $this->nonceTtlSeconds, '1'));
        } catch (Throwable $e) {
            $this->logger->error('Failed to store DPoP nonce', ['exception' => $e]);
        }
    }

    public function createChallengeResponse(string $errorDescription = 'Authorization server requires nonce in DPoP proof.'): JsonResponse
    {
        $nonce = $this->generateNonce();
        $this->storeNonce($nonce);

        return new JsonResponse([
            'error' => 'use_dpop_nonce',
            'error_description' => $errorDescription,
        ], Response::HTTP_BAD_REQUEST, [
            'DPoP-Nonce' => $nonce,
        ]);
    }

    public function isValid(string $nonce): bool
    {
        $key = 'dpop:nonce:' . $nonce;

        try {
            // GETDEL atomically reads and deletes the key, preventing
            // concurrent Swoole coroutines from consuming the same nonce.
            return $this->redisClientFactory->borrow(fn(\Redis $redis) => $redis->getDel($key) !== false);
        } catch (Throwable $e) {
            $this->logger->error('Failed to validate DPoP nonce', ['exception' => $e]);

            return false;
        }
    }
}
