<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Shared\Application\Port\TransactionPortInterface;
use Defuse\Crypto\Key;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/** Refresh writes and response signing either commit together or roll back together. */
#[Exclude]
final class TransactionalAuthorizationServer extends AuthorizationServer
{
    public function __construct(
        ClientRepositoryInterface $clientRepository,
        AccessTokenRepositoryInterface $accessTokenRepository,
        ScopeRepositoryInterface $scopeRepository,
        #[\SensitiveParameter] CryptKeyInterface|string $privateKey,
        #[\SensitiveParameter] Key|string $encryptionKey,
        ResponseTypeInterface $responseType,
        private readonly TransactionPortInterface $transaction,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($clientRepository, $accessTokenRepository, $scopeRepository, $privateKey, $encryptionKey, $responseType);
    }

    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || ($body['grant_type'] ?? null) !== 'refresh_token') {
            return parent::respondToAccessTokenRequest($request, $response);
        }

        $connection = $this->entityManager->getConnection();
        if (!$connection->isAutoCommit() || $connection->getTransactionNestingLevel() !== 0) {
            throw new LogicException('Refresh token issuance requires a top-level transaction.');
        }

        try {
            return $this->transaction->run(function () use ($request, $response, $connection): ResponseInterface {
                // Bound contention without changing this pooled connection's defaults.
                $connection->executeStatement("SELECT set_config('lock_timeout', '5s', true)");
                $connection->executeStatement("SELECT set_config('statement_timeout', '15s', true)");

                // League generates/signs the HTTP response after the grant returns.
                // Committing at the grant boundary would lose rollback on signing errors.
                return parent::respondToAccessTokenRequest($request, $response);
            });
        } catch (Throwable $error) {
            // DoctrineTransaction discards aborted ORM state (and closed contextual
            // managers). Discard the connection too, including uncertain commit ACKs.
            $connection->close();
            throw $error;
        }
    }
}
