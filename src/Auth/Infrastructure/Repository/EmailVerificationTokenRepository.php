<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository;

use App\Auth\Application\DTO\RedeemedEmailVerification;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Writes email_verification_tokens (mapped by EmailVerificationTokenEntity) with single
 * statements, so that replacing and redeeming a token are atomic. The entity is never
 * loaded, so these statements leave no stale managed state behind.
 */
final class EmailVerificationTokenRepository implements EmailVerificationTokenRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function issue(Uuid $userId, Email $email, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO email_verification_tokens (user_id, email, token_hash, created_at, expires_at)
                VALUES (:userId, :email, :tokenHash, :createdAt, :expiresAt)
                ON CONFLICT (user_id) DO UPDATE
                   SET email = EXCLUDED.email,
                       token_hash = EXCLUDED.token_hash,
                       created_at = EXCLUDED.created_at,
                       expires_at = EXCLUDED.expires_at
                SQL,
            [
                'userId' => $userId->toString(),
                'email' => $email->toString(),
                'tokenHash' => self::hash($token),
                'createdAt' => new \DateTimeImmutable(),
                'expiresAt' => $expiresAt,
            ],
            [
                'createdAt' => Types::DATETIMETZ_IMMUTABLE,
                'expiresAt' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );
    }

    public function redeem(string $token, \DateTimeImmutable $at): ?RedeemedEmailVerification
    {
        // Deleting first makes redemption single-use under concurrency; an expired token is
        // removed as well, since it can never be redeemed.
        $row = $this->connection->fetchAssociative(
            'DELETE FROM email_verification_tokens WHERE token_hash = :tokenHash RETURNING user_id, email, expires_at > :at AS valid',
            ['tokenHash' => self::hash($token), 'at' => $at],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );

        if ($row === false || $row['valid'] !== true) {
            return null;
        }

        return new RedeemedEmailVerification(
            Uuid::fromString((string) $row['user_id']),
            Email::fromString((string) $row['email']),
        );
    }

    public function revokeForUser(Uuid $userId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM email_verification_tokens WHERE user_id = :userId',
            ['userId' => $userId->toString()],
        );
    }

    private static function hash(string $token): string
    {
        // Tokens are 256-bit random values, so a fast unsalted hash cannot be brute-forced.
        return hash('sha256', $token);
    }
}
