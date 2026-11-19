<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * A row is inserted, used up by one guarded statement and deleted, never changed through
 * the unit of work. Read-only also keeps a loaded request out of every later flush: the
 * database deletes a client's requests with the client, and a managed request would
 * otherwise make the next flush refuse the removed client as a new entity.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'oauth_pushed_authorization_requests')]
#[ORM\Index(name: 'idx_oauth_pushed_authorization_requests_client_id', columns: ['client_id'])]
#[ORM\UniqueConstraint(name: 'uniq_oauth_pushed_authorization_requests_request_id', columns: ['request_id'])]
class PushedAuthorizationRequestEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    /** The token identifier at the end of the request URI. */
    #[ORM\Column(type: 'text')]
    private string $requestId;

    #[ORM\ManyToOne(targetEntity: ClientEntity::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClientEntity $client;

    #[ORM\Column(type: 'text')]
    private string $redirectUri;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $scopes;

    /** The client's opaque state value, returned with the authorization response. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $state;

    #[ORM\Column(type: 'text')]
    private string $codeChallenge;

    /** Only S256 (chk_oauth_pushed_authorization_requests_code_challenge_method). */
    #[ORM\Column(type: 'text')]
    private string $codeChallengeMethod;

    /** JWK SHA-256 thumbprint of the DPoP key the resulting code is bound to. */
    #[ORM\Column(type: 'text')]
    private string $dpopJkt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    /** @param list<string> $scopes */
    public function __construct(
        Uuid $id,
        string $requestId,
        ClientEntity $client,
        string $redirectUri,
        array $scopes,
        ?string $state,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $dpopJkt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->requestId = $requestId;
        $this->client = $client;
        $this->redirectUri = $redirectUri;
        $this->scopes = $scopes;
        $this->state = $state;
        $this->codeChallenge = $codeChallenge;
        $this->codeChallengeMethod = $codeChallengeMethod;
        $this->dpopJkt = $dpopJkt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): ClientEntity
    {
        return $this->client;
    }
}
