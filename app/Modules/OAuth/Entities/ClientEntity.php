<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    protected $isConfidential = false;

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

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }
}
