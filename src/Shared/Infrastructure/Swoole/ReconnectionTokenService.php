<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Psr\Log\LoggerInterface;
use Swoole\Table;

final class ReconnectionTokenService
{
    private const TTL_SECONDS = 300; // 5 minutes
    private const TOKEN_LENGTH = 24; // 48 hex chars — fits in Swoole Table key limit

    private function __construct(
        private readonly Table $tokens,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function create(int $maxTokens = 4096, ?LoggerInterface $logger = null): self
    {
        // With the default conflict proportion (0.2) Swoole runs out of overflow rows
        // early: measured on Swoole 6.2.1, a 4,096-row table refused about the
        // 2,900th random token. 1.0 makes room for all $maxTokens tokens (0.64 MB).
        $tokens = new Table($maxTokens, 1.0);
        $tokens->column('user_id', Table::TYPE_STRING, 36);
        $tokens->column('created_at', Table::TYPE_INT);
        $tokens->create();

        return new self($tokens, $logger);
    }

    /**
     * Generates and stores a reconnection token for the given user ID.
     *
     * Returns null when the table has no free row even after expired tokens are
     * dropped. The connection then works without a reconnection token.
     */
    public function generate(string $userId): ?string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        if ($this->store($token, $userId)) {
            return $token;
        }

        // Tokens of connections that never reconnect stay until something drops
        // them, and nothing else sweeps this table.
        $this->sweepExpired();
        if ($this->store($token, $userId)) {
            return $token;
        }

        $this->logger?->warning('Reconnection token table is full; the connection gets no reconnection token', [
            'userId' => $userId,
        ]);

        return null;
    }

    /** @phpstan-impure */
    private function store(string $token, string $userId): bool
    {
        // A full table makes set() warn and return false; the caller decides what that means.
        return @$this->tokens->set($token, [
            'user_id' => $userId,
            'created_at' => time(),
        ]);
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

            return null;
        }

        $userId = $row['user_id'];

        $this->logger?->debug('Reconnection token consumed', ['userId' => $userId]);

        // Single-use: delete immediately
        $this->tokens->del($token);

        return $userId;
    }

    /**
     * Voids every pending token of a user, so none of them can restore that identity.
     *
     * @return int Number of tokens removed
     */
    public function revokeForUser(string $userId): int
    {
        $revoked = [];
        foreach ($this->tokens as $token => $row) {
            if ($row['user_id'] === $userId) {
                $revoked[] = (string) $token;
            }
        }
        foreach ($revoked as $token) {
            $this->tokens->del($token);
        }

        return count($revoked);
    }

    /**
     * Remove all expired tokens from the table. generate() calls it when the table is full.
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

            return false;
        }

        return true;
    }
}
