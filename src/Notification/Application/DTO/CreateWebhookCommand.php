<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/**
 * Configures a webhook and issues its signing secret.
 *
 * The fields hold the input as the client sent it; the handler validates them.
 */
final readonly class CreateWebhookCommand
{
    /**
     * @param mixed $url            the destination URL; it must resolve only to allowed addresses
     * @param mixed $categoryFilter a list of notification category values, or null for all categories
     */
    public function __construct(
        public mixed $url,
        public mixed $categoryFilter = null,
    ) {
    }
}
