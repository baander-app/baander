<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\DeliveryIntent;

interface DeliveryIntentResolverInterface
{
    /** @throws \InvalidArgumentException When the message has no valid delivery intent. */
    public function resolve(object $message): DeliveryIntent;
}
