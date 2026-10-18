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
            chainId: new Uuid(),
        );
        $token->setDpopJkt($this->proof->thumbprint());
        $manager->persist($client);
        $manager->persist($token);
        $manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign($privateKey, $token);
    }

    /** The signed access token, also used by negative authentication probes. */
    public function accessToken(): string
    {
        return $this->jwt;
    }

    /** @return list<string> */
    public function proofHeaders(string $method, string $endpoint): array
    {
        return [
            'Authorization: DPoP ' . $this->jwt,
            'DPoP: ' . $this->proof->create($method, $endpoint, $this->jwt),
        ];
    }

    public function webSocketTicket(string $endpoint, string $origin): string
    {
        $response = (new HttpExchange($endpoint, [
            ...$this->proofHeaders('POST', $endpoint),
            'Origin: ' . $origin,
            'Accept: application/json',
        ], 'POST'))->complete(10);
        if ($response->status() !== 200) {
            throw new RuntimeException('WebSocket ticket issuance answered ' . $response->status());
        }
        $ticket = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['data']['ticket'] ?? null;
        if (!is_string($ticket) || preg_match('/\A[a-f0-9]{64}\z/D', $ticket) !== 1) {
            throw new RuntimeException('WebSocket ticket issuance returned an invalid response.');
        }

        return $ticket;
    }

    /** @param list<string> $headers */
    public function open(string $query, array $headers = [], ?int $stopAfterBytes = null, ?int $maxBytesPerSecond = null, int $timeoutSeconds = 60): HttpExchange
    {
        return new HttpExchange($this->endpoint . '?' . $query, [
            ...$headers,
            'Authorization: DPoP ' . $this->jwt,
            'DPoP: ' . $this->proof->create('GET', $this->endpoint, $this->jwt),
        ], stopAfterBytes: $stopAfterBytes, maxBytesPerSecond: $maxBytesPerSecond, timeoutSeconds: $timeoutSeconds);
    }
}
