<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Entities;

use AllowDynamicProperties;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

#[AllowDynamicProperties]
class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }
}
