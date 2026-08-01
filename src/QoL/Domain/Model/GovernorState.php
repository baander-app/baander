<?php

declare(strict_types=1);

namespace App\QoL\Domain\Model;

enum GovernorState: string
{
    case Learning = 'learning';
    case Active = 'active';
}
