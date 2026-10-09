<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/**
 * Changes a webhook's URL, its category filter, or both. A field changes only when its
 * `changes*` flag is set, so a null category filter clears the filter.
 *
 * The fields hold the input as the client sent it; the handler validates them.
 */
final readonly class UpdateWebhookCommand
{
    public function __construct(
        public string $webhookId,
        public bool $changesUrl = false,
        public mixed $url = null,
        public bool $changesCategoryFilter = false,
        public mixed $categoryFilter = null,
    ) {
    }
}
