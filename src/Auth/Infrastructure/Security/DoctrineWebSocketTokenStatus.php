<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\WebSocketTokenStatusInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/** Reads scalar authorization state without reusing managed entities or cached token verdicts. */
final readonly class DoctrineWebSocketTokenStatus implements WebSocketTokenStatusInterface
{
    public function __construct(private Connection $connection) {}

    public function expiresAt(string $userId, string $accessTokenId): ?\DateTimeImmutable
    {
        try {
            $expiry = $this->connection->fetchOne(<<<'SQL'
SELECT token.expires_at
FROM oauth_access_tokens token
JOIN users account ON account.id = token.user_id
WHERE token.token_id = :token_id AND token.user_id = :user_id
  AND NOT token.revoked AND NOT account.disabled
  AND token.expires_at > clock_timestamp()
  AND token.dpop_jkt IS NOT NULL AND token.dpop_jkt <> ''
SQL, ['token_id' => $accessTokenId, 'user_id' => $userId]);
        } catch (Exception $exception) {
            throw new WebSocketTicketUnavailableException('WebSocket authorization state is unavailable.', previous: $exception);
        }

        return is_string($expiry) ? new \DateTimeImmutable($expiry) : null;
    }
}
