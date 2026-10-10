<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Calls the track stream endpoint as a user, with a DPoP-bound access token signed by
 * the server's OAuth key and a fresh proof for every request.
 */
final readonly class StreamClient
{
    private SignedDpopProof $proof;
    private string $jwt;

    public function __construct(
        EntityManagerInterface $manager,
        string $email,
        string $privateKey,
        private string $endpoint,
    ) {
        $user = $manager->getRepository(UserEntity::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserEntity) {
            throw new RuntimeException('No user ' . $email);
        }
        $client = new ClientEntity(new PublicId(), 'Web runtime drill', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $token = new AccessTokenEntity(
            (new Uuid())->toString(),
            $client,
            $user,
            scopes: ['library'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
        $token->setDpopJkt($this->proof->thumbprint());
        $manager->persist($client);
        $manager->persist($token);
        $manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign($privateKey, $token);
    }

    /** @param list<string> $headers */
    public function open(string $query, array $headers = [], ?int $stopAfterBytes = null): HttpExchange
    {
        return new HttpExchange($this->endpoint . '?' . $query, [
            ...$headers,
            'Authorization: DPoP ' . $this->jwt,
            'DPoP: ' . $this->proof->create('GET', $this->endpoint, $this->jwt),
        ], stopAfterBytes: $stopAfterBytes);
    }
}
