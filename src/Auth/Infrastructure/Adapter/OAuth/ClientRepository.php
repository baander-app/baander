<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface as DomainRepository;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\PublicId;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Anti-corruption layer adapting the league/oauth2-server ClientRepositoryInterface
 * to our domain repository.
 */
final class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        try {
            $publicId = new PublicId($clientIdentifier);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $domain = $this->domainRepository->findClientByPublicId($publicId);

        if ($domain === null || $domain->isRevoked()) {
            return null;
        }

        // The domain model is the source of truth — return a league-compatible
        // entity built from it. For the league protocol we only need the
        // entity interface, not a managed Doctrine entity.
        $entity = new ClientEntity(
            $domain->getPublicId(),
            $domain->getName(),
            $this->jsonEncoder->encode($domain->getRedirectUris(), 'json'),
            $domain->getSecret(),
            null,
            $domain->isPersonalAccessClient(),
            $domain->isPasswordClient(),
            $domain->isDeviceClient(),
            $domain->isConfidential(),
            $domain->isFirstParty(),
        );

        return $entity;
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        try {
            $publicId = new PublicId($clientIdentifier);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $client = $this->domainRepository->findClientByPublicId($publicId);

        if ($client === null || $client->isRevoked()) {
            return false;
        }

        if (!$client->isConfidential()) {
            return true;
        }

        if ($clientSecret === null || $clientSecret === '') {
            return false;
        }

        $storedSecret = $client->getSecret();

        if ($storedSecret === null) {
            return false;
        }

        return hash_equals($storedSecret, $clientSecret);
    }
}
