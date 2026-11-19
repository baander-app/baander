<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Baander's own client, the one whose tokens are first-party.
 *
 * The API authenticator treats a token as first-party only when its client is the
 * first-party client registered under `auth.spa_client_id`; any other client's token is
 * delegated and reaches only what its scopes cover. Acceptance tests that act as the web
 * or desktop app mint their tokens on this row. It is created when missing and shared by
 * every test, as `app:auth:setup-clients` seeds it; removing it cascades to its tokens.
 */
final class FirstPartyOAuthClient
{
    public static function entity(ContainerInterface $container, EntityManagerInterface $manager): ClientEntity
    {
        $publicId = $container->getParameter('auth.spa_client_id');
        if (!is_string($publicId)) {
            throw new \LogicException('The first-party client ID is not configured.');
        }

        $client = $manager->getRepository(ClientEntity::class)->findOneBy(['publicId' => PublicId::fromString($publicId)]);
        if ($client !== null) {
            return $client;
        }

        $client = new ClientEntity(
            PublicId::fromString($publicId),
            'Baander',
            json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR),
            passwordClient: true,
            firstParty: true,
        );
        $manager->persist($client);
        $manager->flush();

        return $client;
    }
}
