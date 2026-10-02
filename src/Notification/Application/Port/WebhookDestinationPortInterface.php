<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface WebhookDestinationPortInterface
{
    /** @return array{host: string, ips: list<string>}|null */
    public function resolve(string $url): ?array;
}
