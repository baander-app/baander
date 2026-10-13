<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use Psr\Log\LoggerInterface;
use RedisException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues and consumes the single-use DPoP nonces of the token endpoints.
 *
 * A nonce is handed out only after Redis stored it. When Redis cannot be reached,
 * the pool is exhausted or a read times out, every method raises
 * {@see DpopStoreUnavailableException} instead of guessing: a nonce that was not
 * stored would be challenged again, and a nonce that cannot be checked is not valid.
 */
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

    /**
     * Generates a nonce and hands it out once Redis stored it.
     *
     * @throws DpopStoreUnavailableException when the nonce could not be stored; no nonce is issued then.
     */
    public function issueNonce(): string
    {
        $nonce = $this->generateNonce();
        $this->storeNonce($nonce);

        return $nonce;
    }

    /**
     * Sets the next nonce on a response whose tokens are already committed (R8): the nonce
     * is best-effort there, so a store failure leaves the header out and the client's next
     * token request gets one challenge instead.
     */
    public function attachNextNonce(Response $response): void
    {
        try {
            $nonce = $this->issueNonce();
        } catch (DpopStoreUnavailableException) {
            return;
        }
        $response->headers->set('DPoP-Nonce', $nonce);
    }

    /** @throws DpopStoreUnavailableException */
    public function storeNonce(string $nonce): void
    {
        try {
            $this->redisClientFactory->borrow(fn(\Redis $redis) => $redis->setex('dpop:nonce:' . $nonce, $this->nonceTtlSeconds, '1'));
        } catch (RedisException | RedisPoolExhaustedException $e) {
            $this->logger->warning('Failed to store DPoP nonce', ['exception' => $e]);

            throw DpopStoreUnavailableException::nonceStore($e);
        }
    }

    /**
     * Stores a fresh nonce, then answers the `use_dpop_nonce` challenge that carries it.
     *
     * @throws DpopStoreUnavailableException when the nonce could not be stored; no challenge is issued then.
     */
    public function createChallengeResponse(string $errorDescription = 'Authorization server requires nonce in DPoP proof.'): JsonResponse
    {
        $nonce = $this->issueNonce();

        return new JsonResponse([
            'error' => 'use_dpop_nonce',
            'error_description' => $errorDescription,
        ], Response::HTTP_BAD_REQUEST, [
            'DPoP-Nonce' => $nonce,
        ]);
    }

    /** @throws DpopStoreUnavailableException */
    public function isValid(string $nonce): bool
    {
        $key = 'dpop:nonce:' . $nonce;

        try {
            // GETDEL atomically reads and deletes the key, preventing
            // concurrent Swoole coroutines from consuming the same nonce.
            return $this->redisClientFactory->borrow(fn(\Redis $redis) => $redis->getDel($key) !== false);
        } catch (RedisException | RedisPoolExhaustedException $e) {
            $this->logger->warning('Failed to validate DPoP nonce', ['exception' => $e]);

            throw DpopStoreUnavailableException::nonceStore($e);
        }
    }
}
