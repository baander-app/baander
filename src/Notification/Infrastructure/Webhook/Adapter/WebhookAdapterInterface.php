<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook\Adapter;

interface WebhookAdapterInterface
{
    /**
     * Format a notification payload for a specific webhook platform.
     *
     * @return array<string, mixed>
     */
    public function formatPayload(string $title, string $body, string $category, string $url): array;
}
