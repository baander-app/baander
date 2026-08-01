<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook\Adapter;

final class SlackWebhookAdapter implements WebhookAdapterInterface
{
    public function formatPayload(string $title, string $body, string $category, string $url): array
    {
        return [
            'attachments' => [
                [
                    'title' => $title,
                    'text' => $body,
                    'color' => '#5865F2',
                    'title_link' => $url,
                ],
            ],
        ];
    }
}
