<?php

declare(strict_types=1);

namespace App\Notification\Interface\Resource;

use App\Notification\Application\DTO\IssuedWebhookSecret;
use App\Notification\Application\DTO\WebhookView;
use App\Shared\Interface\Resource\AbstractResource;

/**
 * A webhook as the webhook API and the app:webhook:* commands print it. Only the payloads of
 * creation and rotation carry the plain secret, because only those use cases issue one.
 */
final class WebhookResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof WebhookView);

        return [
            'id' => $source->id->toString(),
            'url' => $source->url,
            'category_filter' => $source->categoryFilter,
            'created_at' => $source->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $source->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> the created webhook with its secret and signing version */
    public static function created(IssuedWebhookSecret $issued): array
    {
        $webhook = self::from($issued->webhook);

        return [
            'id' => $webhook['id'],
            'url' => $webhook['url'],
            'category_filter' => $webhook['category_filter'],
            'secret' => $issued->secret,
            'signing_version' => $issued->webhook->signingVersion,
            'created_at' => $webhook['created_at'],
            'updated_at' => $webhook['updated_at'],
        ];
    }

    /** @return array{id: string, secret: string, signing_version: int} */
    public static function rotated(IssuedWebhookSecret $issued): array
    {
        return [
            'id' => $issued->webhook->id->toString(),
            'secret' => $issued->secret,
            'signing_version' => $issued->webhook->signingVersion,
        ];
    }
}
