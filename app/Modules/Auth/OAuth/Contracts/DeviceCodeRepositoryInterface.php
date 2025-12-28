<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Contracts;

use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface as LeagueDeviceCodeRepositoryInterface;

interface DeviceCodeRepositoryInterface extends LeagueDeviceCodeRepositoryInterface
{
    public function updateDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void;
}
