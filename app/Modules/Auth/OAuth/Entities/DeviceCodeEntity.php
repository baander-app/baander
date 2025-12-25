<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\DeviceCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class DeviceCodeEntity implements DeviceCodeEntityInterface
{
    use DeviceCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;
}
