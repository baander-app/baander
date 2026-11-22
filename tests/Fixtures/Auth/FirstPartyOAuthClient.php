<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Baander's own client, the one whose tokens are first-party.
 *
 * The API authenticator treats a token as first-party only when its client is the
 * first-party client registered under `auth.spa_client_id` and its chain began with a
 * password or passkey sign-in; any other token is delegated and reaches only what its
 * scopes cover. Acceptance tests that act as the web or desktop app mint their tokens on
 * this row and name the grant they stand for. It is created when missing and shared by
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

    /**
     * An unsaved access token of this client for $user, bound to the DPoP key $dpopJkt. The
     * grant names how the session began: a password or passkey sign-in gives the token
     * first-party authority, and any other grant leaves it delegated without scopes.
     */
    public static function accessToken(
        ContainerInterface $container,
        EntityManagerInterface $manager,
        UserEntity $user,
        SignInGrant $grant,
        string $dpopJkt,
        \DateTimeImmutable $expiresAt = new \DateTimeImmutable('+1 hour'),
    ): AccessTokenEntity {
        $token = new AccessTokenEntity(
            new Uuid()->toString(),
            self::entity($container, $manager),
            $grant,
            $user,
            expiresAt: $expiresAt,
        );
        $token->setDpopJkt($dpopJkt);

        return $token;
    }
}
