<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    protected bool $isConfidential = false;
    protected bool $isFirstParty = false;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setRedirectUri(string|array $uri): void
    {
        $this->redirectUri = $uri;
    }

    public function setConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
    }

    public function setFirstParty(bool $isFirstParty): void
    {
        $this->isFirstParty = $isFirstParty;
    }

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }

    public function isFirstParty(): bool
    {
        return $this->isFirstParty;
    }
}
