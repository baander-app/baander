<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequestState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\PushedAuthorizationRequestEntity;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A request is created through the unit of work, flushed and detached in the method that
 * creates it. Reads go through the mapping as DQL and return plain rows, never managed
 * entities, so a lookup cannot answer from a copy that another transaction has since used
 * up. One statement uses a request up and stays SQL for the reason given where it stands.
 */
final readonly class PushedAuthorizationRequestRepository implements PushedAuthorizationRequestRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PushedAuthorizationRequest $request): void
    {
        $entity = new PushedAuthorizationRequestEntity(
            $request->getId(),
            $request->getRequestId()->toString(),
            $this->entityManager->getReference(ClientEntity::class, $request->getClientId()),
            $request->getRedirectUri(),
            $request->getScopeIdentifiers(),
            $request->getState(),
            $request->getCodeChallenge(),
            $request->getCodeChallengeMethod(),
            $request->getDpopJkt(),
            $request->getExpiresAt(),
            $request->getCreatedAt(),
        );
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->entityManager->detach($entity);
    }

    public function findByRequestId(TokenId $requestId): ?PushedAuthorizationRequest
    {
        $rows = $this->entityManager->createQuery(
            'SELECT r.id, r.requestId, IDENTITY(r.client) AS clientId, r.redirectUri, r.scopes, r.state,'
            . ' r.codeChallenge, r.codeChallengeMethod, r.dpopJkt, r.expiresAt, r.createdAt, r.consumedAt'
            . ' FROM ' . PushedAuthorizationRequestEntity::class . ' r WHERE r.requestId = :requestId',
        )->setParameter('requestId', $requestId->toString())->getArrayResult();

        $row = array_first($rows);

        return $row === null ? null : $this->toDomain($row);
    }

    public function consume(TokenId $requestId, Uuid $clientId): bool
    {
        // One conditional UPDATE decides between concurrent decisions on the same request. It
        // stays SQL because DQL has no clock_timestamp(): CURRENT_TIMESTAMP() would judge the
        // expiry by the start of the decision's transaction rather than by the moment of use.
        return $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE oauth_pushed_authorization_requests
                   SET consumed_at = clock_timestamp()
                 WHERE request_id = :requestId
                   AND client_id = :clientId
                   AND consumed_at IS NULL
                   AND expires_at > clock_timestamp()
                SQL,
            ['requestId' => $requestId->toString(), 'clientId' => $clientId->toString()],
        ) === 1;
    }

    public function deleteExpiredBefore(DateTimeImmutable $cutoff): int
    {
        // The table holds a few minutes of requests between daily purges, so a sequential
        // scan costs less than an expires_at index maintained on every push.
        return (int) $this->entityManager->createQuery(
            'DELETE FROM ' . PushedAuthorizationRequestEntity::class . ' r WHERE r.expiresAt < :cutoff',
        )->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)->execute();
    }

    /** @param array<array-key, mixed> $row */
    private function toDomain(array $row): PushedAuthorizationRequest
    {
        /** @var list<string> $scopes */
        $scopes = $row['scopes'];

        return PushedAuthorizationRequest::reconstitute(new PushedAuthorizationRequestState(
            id: $row['id'],
            requestId: TokenId::fromString($row['requestId']),
            clientId: $row['clientId'] instanceof Uuid ? $row['clientId'] : Uuid::fromString((string) $row['clientId']),
            redirectUri: $row['redirectUri'],
            scopes: array_map(static fn (string $scope): Scope => new Scope($scope), $scopes),
            state: $row['state'],
            codeChallenge: $row['codeChallenge'],
            codeChallengeMethod: $row['codeChallengeMethod'],
            dpopJkt: $row['dpopJkt'],
            expiresAt: $row['expiresAt'],
            createdAt: $row['createdAt'],
            consumedAt: $row['consumedAt'],
        ));
    }
}
