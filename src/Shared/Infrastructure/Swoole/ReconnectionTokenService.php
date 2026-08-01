<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Psr\Log\LoggerInterface;
use Swoole\Table;

final class ReconnectionTokenService
{
    private const TTL_SECONDS = 300; // 5 minutes
    private const TOKEN_LENGTH = 24; // 48 hex chars — fits in Swoole Table key limit

    /** @var array<string, int> Token => created_at timestamp (local cache for TTL check) */
    private array $createdAtCache = [];

    private function __construct(
        private readonly Table $tokens,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function create(int $maxTokens = 4096, ?LoggerInterface $logger = null): self
    {
        $tokens = new Table($maxTokens);
        $tokens->column('user_id', Table::TYPE_STRING, 36);
        $tokens->column('created_at', Table::TYPE_INT);
        $tokens->create();

        return new self($tokens, $logger);
    }

    /**
     * Generate a reconnection token for the given user ID.
     */
    public function generate(string $userId): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $now = time();

        $this->tokens->set($token, [
            'user_id' => $userId,
            'created_at' => $now,
        ]);

        $this->createdAtCache[$token] = $now;

        return $token;
    }

    /**
     * Consume a reconnection token. Returns the user ID if valid and not expired,
     * or null if the token is invalid, expired, or already consumed (single-use).
     */
    public function consume(string $token): ?string
    {
        $row = $this->tokens->get($token);
        if ($row === false) {
            return null;
        }

        if (time() - (int) $row['created_at'] > self::TTL_SECONDS) {
            $this->tokens->del($token);
            unset($this->createdAtCache[$token]);

            return null;
        }

        $userId = $row['user_id'];

        $this->logger?->debug('Reconnection token consumed', ['userId' => $userId]);

        // Single-use: delete immediately
        $this->tokens->del($token);
        unset($this->createdAtCache[$token]);

        return $userId;
    }

    /**
     * Remove all expired tokens from the table. Call periodically (e.g., in onWorkerStart).
     *
     * @return int Number of tokens removed
     */
    public function sweepExpired(): int
    {
        $cutoff = time() - self::TTL_SECONDS;
        $removed = 0;

        foreach ($this->tokens as $token => $row) {
            if ((int) $row['created_at'] < $cutoff) {
                $this->tokens->del($token);
                unset($this->createdAtCache[$token]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Check if a token exists (without consuming it). Useful for validation.
     */
    public function exists(string $token): bool
    {
        $row = $this->tokens->get($token);
        if ($row === false) {
            return false;
        }

        // Clean up expired tokens on check
        if (time() - (int) $row['created_at'] > self::TTL_SECONDS) {
            $this->tokens->del($token);
            unset($this->createdAtCache[$token]);

            return false;
        }

        return true;
    }
}
