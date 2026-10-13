<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\Service\IssuanceLockTimeout;
use App\Auth\Domain\Exception\TokenChainViolationException;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Application\Port\TransactionPortInterface;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for the Refresh Token grant (token rotation).
 *
 * Validates the refresh token, checks chain integrity for replay attacks,
 * marks the old token as used, and returns a new access/refresh token pair.
 *
 * A revoked client answers invalid_client (OAuthProtocolException); every other
 * verdict on the token is a RefreshTokenRejectedException, which the token endpoint
 * reports as invalid_grant; a token of a disabled account is one of them. Any other
 * exception, such as a lost database connection, is a server fault and says nothing
 * about the token. The rotation holds the client's row lock against a concurrent
 * revocation, as TokenPairIssuer does, and bounds its lock waits (IssuanceLockTimeout),
 * so a stuck lock is answered as a server fault inside the client's attempt timeout.
 * Both transactions run through the transaction port, whose failure cleanup discards
 * rolled-back entities and releases a manager a flush failure closed, so the failure
 * does not poison the coroutine's manager.
 *
 * Lost-response grace: a token that was already consumed, presented again with a
 * proof from its own DPoP key, is judged on rows read under the row lock inside the
 * issuing transaction. Within the grace window, while every live replacement of it
 * is unused, those replacements and their access tokens are revoked and a fresh pair
 * descends from the presented token; its used_at stays as the first consumption left
 * it. A replacement already used, a presentation outside the window, or a token a
 * grace superseded presented while its chain still holds a live token, is reuse: the
 * chain is revoked in a transaction of its own that commits before the rejection. A
 * proof from a different key is refused without revoking anything. The loser of two
 * simultaneous presentations, whose conditional consume changed no row, runs the same
 * locked evaluation, so two presentations leave exactly one usable pair.
 */
final class RefreshTokenHandler
{
    /** The longest grace window the configuration accepts, in seconds. */
    private const int MAX_REUSE_GRACE_SECONDS = 60;

    private readonly DateInterval $accessTokenTtl;
    private readonly DateInterval $refreshTokenTtl;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly TokenChainValidator $chainValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly JwtGeneratorInterface $jwtGenerator,
        private readonly TokenMetadataRepositoryInterface $tokenMetadataRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly TransactionPortInterface $transaction,
        private readonly LoggerInterface $logger,
        private readonly LiveConnectionsPortInterface $liveConnections,
        int $accessTokenTtl,
        int $refreshTokenTtl,
        #[Autowire(param: 'auth.refresh.reuse_grace_seconds')]
        private readonly int $reuseGraceSeconds,
    ) {
        if ($reuseGraceSeconds < 0 || $reuseGraceSeconds > self::MAX_REUSE_GRACE_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'auth.refresh.reuse_grace_seconds must be between 0 and %d, got %d.',
                self::MAX_REUSE_GRACE_SECONDS,
                $reuseGraceSeconds,
            ));
        }
        $this->accessTokenTtl = new DateInterval(sprintf('PT%dS', $accessTokenTtl));
        $this->refreshTokenTtl = new DateInterval(sprintf('PT%dS', $refreshTokenTtl));
    }

    #[AsMessageHandler]
    public function __invoke(RefreshTokenCommand $command): TokenResponseDTO
    {
        try {
            $refreshTokenId = TokenId::fromString($command->getRefreshTokenId());
        } catch (InvalidArgumentException) {
            // No token was ever issued in this form.
            throw RefreshTokenRejectedException::notFound();
        }
        $refreshToken = $this->refreshTokenRepository->findByTokenId($refreshTokenId);

        if ($refreshToken === null) {
            throw RefreshTokenRejectedException::notFound();
        }

        // A used token that was revoked afterwards belongs to a chain that has ended;
        // there is nothing left to grace or to revoke. A revoked unused token may be
        // one a grace superseded, which the locked evaluation below decides.
        if ($refreshToken->isRevoked() && $refreshToken->hasBeenUsed()) {
            throw RefreshTokenRejectedException::revoked();
        }

        if ($refreshToken->isExpired()) {
            throw RefreshTokenRejectedException::expired();
        }

        // At the token endpoint the token must belong to the client that authenticated
        // (RFC 6749 section 6). Checked first, so another client's revocation is not
        // reported as a failure of the authenticated one.
        $tokenClient = $refreshToken->getAccessToken()->getClient();
        $clientId = $command->getClientId();
        if ($clientId !== null && !$tokenClient->getId()->equals($clientId)) {
            throw RefreshTokenRejectedException::issuedToAnotherClient();
        }
        // A revoked client's tokens cannot be refreshed. This early check uses the
        // loaded state; the transaction below repeats it under the row lock.
        if ($tokenClient->isRevoked()) {
            throw self::clientRevoked();
        }

        // A disabled account's sessions end. Disabling revokes the tokens; this also
        // rejects a pair that a refresh racing the disable rotated past that revocation.
        // The user loaded with the token does not carry the flag, so ask the repository.
        $tokenUser = $refreshToken->getAccessToken()->getUser();
        if ($tokenUser !== null) {
            $user = $this->userRepository->findByUuid($tokenUser->getId());
            if ($user === null || $user->isDisabled()) {
                throw RefreshTokenRejectedException::accountDisabled();
            }
        }

        // RFC 9449 §5: a refresh token is redeemable only with the DPoP key its
        // token pair was issued to. Unbound tokens fail closed, and a different key
        // never revokes: the key holder, not the presenter, keeps the chain.
        $boundJkt = $refreshToken->getAccessToken()->getDpopJkt();
        $proofJkt = $command->getDpopJkt();
        if ($boundJkt === null || $boundJkt === '' || $proofJkt === null || $proofJkt === ''
            || !hash_equals($boundJkt, $proofJkt)) {
            $this->logger->warning('Refresh token refused: the proof was signed by a different key.', [
                'chain_id' => $refreshToken->getChainId()?->toString(),
                'reason' => 'different key',
            ]);

            throw RefreshTokenRejectedException::proofBindingMismatch();
        }

        // The pre-transaction read only routes. A used token, or a revoked unused one,
        // presented with its own key is judged on rows read under the row lock; an
        // unused, unrevoked token takes the rotation path. Tokens without a chainId are
        // outside the rotation model: no validation, no grace.
        $chainId = $refreshToken->getChainId();
        if ($chainId === null && $refreshToken->isRevoked()) {
            throw RefreshTokenRejectedException::revoked();
        }
        $lockedEvaluation = $chainId !== null && ($refreshToken->hasBeenUsed() || $refreshToken->isRevoked());
        if ($chainId !== null && !$lockedEvaluation) {
            try {
                $this->chainValidator->validateWithLoadedPrevious($refreshToken);
            } catch (TokenChainViolationException $violation) {
                throw RefreshTokenRejectedException::chainViolated($violation);
            }
        }

        try {
            return $this->transaction->run(function () use ($refreshTokenId, $command, $tokenClient, $lockedEvaluation): TokenResponseDTO {
                IssuanceLockTimeout::apply($this->entityManager);
                if (!$this->clientRepository->lockActiveClientForIssuance($tokenClient->getId())) {
                    throw self::clientRevoked();
                }

                if ($lockedEvaluation) {
                    return $this->evaluateUnderLock($refreshTokenId, $command);
                }

                // Atomically consume the refresh token. If another request already used
                // this token, the conditional UPDATE affects zero rows: the loser of the
                // race gets the same locked verdict as a token presented again.
                $consumedToken = $this->refreshTokenRepository->consumeByTokenId($refreshTokenId);
                if ($consumedToken === null) {
                    return $this->evaluateUnderLock($refreshTokenId, $command);
                }

                // The conditional UPDATE already stamped used_at on the database clock, which
                // the grace window is measured on; saving the token again would restamp it
                // with this process's clock.
                $oldAccessToken = $consumedToken->getAccessToken();

                // Revoke the old access token
                $oldAccessToken->revoke();
                $this->accessTokenRepository->save($oldAccessToken, false);

                return $this->issuePair($consumedToken, $command);
            });
        } catch (RefreshTokenRejectedException $rejection) {
            // The issuing transaction rolled back; a reuse verdict now ends the chain.
            if ($rejection->chainToRevoke !== null) {
                $this->revokeChain($rejection->chainToRevoke, (string) $rejection->reuseReason);
            }
            throw $rejection;
        }
    }

    /**
     * Judges a token that was already consumed, or revoked unused, on its row and its
     * successors as they are under the row lock. Returns the fresh pair of a
     * grace; every other outcome is a rejection, which rolls the transaction back.
     */
    private function evaluateUnderLock(TokenId $refreshTokenId, RefreshTokenCommand $command): TokenResponseDTO
    {
        $presented = $this->refreshTokenRepository->lockByTokenId($refreshTokenId);
        if ($presented === null) {
            throw RefreshTokenRejectedException::notFound();
        }
        $chainId = $presented->getChainId();
        if ($chainId === null) {
            // Outside the rotation model there is no chain to grace into.
            throw RefreshTokenRejectedException::concurrentLoser();
        }
        $successors = $this->refreshTokenRepository->lockSuccessors($presented->getId());

        if ($presented->isRevoked()) {
            if (!$presented->hasBeenUsed() && $this->refreshTokenRepository->chainHoldsLiveToken($chainId)) {
                // Superseded by a grace, and the client that was graced is still signed in.
                throw RefreshTokenRejectedException::supersededTokenPresented($chainId);
            }

            throw RefreshTokenRejectedException::revoked();
        }

        if (!$presented->hasBeenUsed()) {
            // Only the consume race reaches an unused, unrevoked row: the conditional
            // UPDATE refused it for the one condition left, its expiry.
            throw RefreshTokenRejectedException::expired();
        }

        if ($this->reuseGraceSeconds === 0 || !$this->refreshTokenRepository->consumedWithin($presented->getId(), $this->reuseGraceSeconds)) {
            throw RefreshTokenRejectedException::outsideGraceWindow($chainId);
        }

        foreach ($successors as $successor) {
            if ($successor->hasBeenUsed()) {
                throw RefreshTokenRejectedException::replacementUsed($chainId);
            }
        }

        $live = array_values(array_filter($successors, static fn (RefreshToken $successor): bool => !$successor->isRevoked()));
        if ($live === []) {
            // A competing grace already superseded every replacement; its pair stands.
            $this->logger->info('Refresh token refused: a concurrent request consumed it and no replacement is left to supersede.', [
                'chain_id' => $chainId->toString(),
                'reason' => 'concurrent loser',
            ]);

            throw RefreshTokenRejectedException::concurrentLoser();
        }

        // The grace: the live unused replacements and their access tokens go, by id and
        // before anything is persisted; the fresh pair descends from the presented token,
        // whose used_at stays as the first consumption left it.
        $accessTokenIds = [];
        foreach ($live as $successor) {
            if (!$successor->getAccessToken()->isRevoked()) {
                $accessTokenIds[] = $successor->getAccessToken()->getId();
            }
        }
        $revokedRefreshTokens = $this->refreshTokenRepository->revokeByIds(
            array_map(static fn (RefreshToken $successor) => $successor->getId(), $live),
        );
        if ($revokedRefreshTokens !== count($live)) {
            throw new LogicException(sprintf('The grace revoked %d of %d live replacements.', $revokedRefreshTokens, count($live)));
        }
        $revokedAccessTokens = $this->accessTokenRepository->revokeByIds($accessTokenIds);
        if ($revokedAccessTokens !== count($accessTokenIds)) {
            throw new LogicException(sprintf('The grace revoked %d of %d replacement access tokens.', $revokedAccessTokens, count($accessTokenIds)));
        }

        $response = $this->issuePair($presented, $command);
        $this->logger->info('Refresh token grace: a consumed token presented again within the window got a fresh pair.', [
            'chain_id' => $chainId->toString(),
            'reason' => 'grace',
            'superseded' => count($live),
        ]);

        return $response;
    }

    /**
     * Issues the next pair of the chain from the presented token's access token, writes
     * it, carries the client fingerprint binding over and signs inside the transaction.
     */
    private function issuePair(RefreshToken $predecessor, RefreshTokenCommand $command): TokenResponseDTO
    {
        $template = $predecessor->getAccessToken();
        $chainId = $predecessor->getChainId();

        $newAccessToken = AccessToken::issue(
            $template->getClient(),
            $template->getUser(),
            $template->getScopes(),
            $template->getName(),
            $this->accessTokenTtl,
            $chainId,
            $template->getDpopJkt(),
        );

        $newRefreshToken = RefreshToken::issue(
            $newAccessToken,
            $chainId,
            $this->refreshTokenTtl,
            $predecessor, // Chain link to previous
        );

        $this->accessTokenRepository->save($newAccessToken, false);
        $this->refreshTokenRepository->save($newRefreshToken, false);
        $this->entityManager->flush();

        // The replacement keeps the client fingerprint binding of the token it rotates.
        $previous = $this->tokenMetadataRepository->findByTokenId($template->getTokenId());
        $this->tokenMetadataRepository->save(TokenMetadata::create(
            tokenId: $newAccessToken->getId(),
            userAgent: $command->getUserAgent(),
            clientFingerprint: $previous?->getClientFingerprint(),
            ipAddress: $command->getIpAddress(),
        ));

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($newAccessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $newRefreshToken->getTokenId()->toString(),
            scopes: $newAccessToken->getScopeIdentifiers(),
        );
    }

    /**
     * A reuse verdict ends the chain: refresh rows before access rows, each in id order,
     * in a transaction of its own that commits before the rejection is answered.
     * The chain's WebSocket connections are closed afterwards, as a logout closes them:
     * whoever presented the replayed token must not keep a live socket either.
     */
    private function revokeChain(ChainId $chainId, string $reason): void
    {
        $accessTokenIds = $this->transaction->run(function () use ($chainId): array {
            $this->refreshTokenRepository->revokeByChainId($chainId);
            // Listed under the refresh-row locks, so a rotation that committed a replacement
            // before this verdict took them is in the list the web server closes.
            $accessTokenIds = $this->accessTokenRepository->findTokenIdsByChainId($chainId);
            $this->accessTokenRepository->revokeByChainId($chainId);

            return $accessTokenIds;
        });
        $this->logger->warning('Refresh token reuse: {reason}; the chain is revoked.', [
            'chain_id' => $chainId->toString(),
            'reason' => $reason,
        ]);
        $this->closeLiveConnections($accessTokenIds, $chainId);
    }

    /**
     * The verdict stands whatever happens here: the chain is revoked and the rejection is
     * answered. A socket that could not be closed is closed by the socket reconciliation
     * within its next tick.
     *
     * @param list<string> $accessTokenIds
     */
    private function closeLiveConnections(array $accessTokenIds, ChainId $chainId): void
    {
        try {
            $this->liveConnections->closeForAccessTokens($accessTokenIds);
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning(
                'No web server runs in this container, so the open WebSocket connections of token chain "{chain}" were not closed.',
                ['chain' => $chainId->toString()],
            );
        } catch (ServerControlException $exception) {
            $this->logger->error(
                'The web server did not close the WebSocket connections of the revoked token chain "{chain}".',
                ['chain' => $chainId->toString(), 'connections' => count($accessTokenIds), 'exception' => $exception],
            );
        }
    }

    private static function clientRevoked(): OAuthProtocolException
    {
        return OAuthProtocolException::invalidClient('The client has been revoked.');
    }
}
