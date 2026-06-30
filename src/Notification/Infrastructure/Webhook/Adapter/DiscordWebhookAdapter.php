<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook\Adapter;

final class DiscordWebhookAdapter implements WebhookAdapterInterface
{
    public function formatPayload(string $title, string $body, string $category, string $url): array
    {
        return [
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $body,
                    'color' => 5_814_783,
                    'url' => $url,
                ],
            ],
        ];
    }
}
