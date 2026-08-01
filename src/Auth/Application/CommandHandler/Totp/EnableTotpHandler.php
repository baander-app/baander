<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class EnableTotpHandler
{
    /**
     * @param CacheItemPoolInterface $cache Redis-backed cache for pending TOTP secrets
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly TotpVerifierInterface $totpVerifier,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(EnableTotpCommand $command): void
    {
        $userId = Uuid::fromString($command->getUserId());

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null) {
            throw new RuntimeException(sprintf('User "%s" not found.', $userId->toString()));
        }

        // Retrieve the pending TOTP secret that was stored during setup
        $pendingSecret = $this->getPendingSecret($userId->toString());
        if ($pendingSecret === null) {
            throw new RuntimeException(
                'No pending TOTP setup found. Please start the setup process again by calling /api/auth/totp/setup.'
            );
        }

        // Verify the TOTP code provided by the user against the server-generated secret
        if (!$this->totpVerifier->verifyCode($pendingSecret, $command->getCode())) {
            throw new RuntimeException('Invalid TOTP code. Please verify the code from your authenticator app.');
        }

        $user->setTotpSecret($pendingSecret);
        $this->userRepository->save($user);

        // Remove the pending secret after successful enable to prevent replay
        $this->cache->deleteItem($this->pendingSecretKey($userId->toString()));
    }

    private function pendingSecretKey(string $userId): string
    {
        return sprintf('totp_pending_secret_%s', $userId);
    }

    /**
     * Retrieve the pending TOTP secret from Redis.
     *
     * Returns null if no secret exists or it has expired (TTL elapsed).
     */
    private function getPendingSecret(string $userId): ?string
    {
        try {
            /** @var CacheItem|null $item */
            $item = $this->cache->getItem($this->pendingSecretKey($userId));
            if (!$item->isHit()) {
                return null;
            }

            return $item->get();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Failed to retrieve pending TOTP secret from cache.', 0, $e);
        }
    }
}
