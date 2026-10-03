<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Serialize one-time consumption; replay remains League's reject-only policy. */
#[Exclude]
final class TransactionalRefreshTokenGrant extends RefreshTokenGrant
{
    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($refreshTokenRepository);
    }

    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        // Authenticate the encrypted payload/client before acquiring any token lock.
        $token = parent::validateOldRefreshToken($request, $clientId);
        $tokenId = $token['refresh_token_id'] ?? null;
        if (!is_string($tokenId) || $tokenId === '') {
            throw OAuthServerException::invalidRefreshToken('Invalid refresh token identifier');
        }

        $connection = $this->entityManager->getConnection();
        if ($connection->getTransactionNestingLevel() !== 1) {
            throw new LogicException('Refresh token consumption requires the endpoint transaction.');
        }
        $lockedToken = $connection->fetchAssociative(
            'SELECT refresh.id, access.dpop_jkt FROM oauth_refresh_tokens refresh '
            . 'JOIN oauth_access_tokens access ON access.id = refresh.access_token_id '
            . 'WHERE refresh.token_id = :tokenId FOR UPDATE OF refresh, access',
            ['tokenId' => $tokenId],
        );
        $proofJkt = $request->getAttribute('_dpop_jkt');
        $storedJkt = $lockedToken === false ? null : $lockedToken['dpop_jkt'];
        if (!is_string($proofJkt) || $proofJkt === '' || !is_string($storedJkt) || $storedJkt === ''
            || !hash_equals($storedJkt, $proofJkt)) {
            throw OAuthServerException::invalidRefreshToken('Refresh token proof binding does not match');
        }
        // Parent validation can see stale ORM state, or overlap another request.
        // Check the authoritative row again after obtaining its lock. The UPDATE
        // also protects callers whose domain path consumed used_at without revoke.
        $consumed = $connection->executeStatement(
            'UPDATE oauth_refresh_tokens SET used_at = NOW(), updated_at = NOW() '
            . 'WHERE token_id = :tokenId AND used_at IS NULL AND revoked = FALSE '
            . 'AND (expires_at IS NULL OR expires_at > clock_timestamp())',
            ['tokenId' => $tokenId],
        );
        if ($consumed !== 1) {
            throw OAuthServerException::invalidRefreshToken('Token has already been used or is unavailable');
        }

        $entity = $this->entityManager->getRepository(RefreshTokenEntity::class)->findOneBy(['tokenId' => $tokenId]);
        if ($entity === null) {
            throw OAuthServerException::invalidRefreshToken('Token is unavailable');
        }
        // Parent validation may already have hydrated this entity before the SQL
        // claim. Keep its managed used_at state aligned with the authoritative row.
        $this->entityManager->refresh($entity);

        return $token;
    }
}
