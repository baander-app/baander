<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Notification\Application\Port\WebhookDestinationPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;

/**
 * Validates the webhook input that the API and the console pass to the webhook use cases.
 */
final readonly class WebhookInput
{
    public const string DESTINATION_REJECTED = 'Webhook destination is not allowed or cannot be resolved.';
    public const string CATEGORY_FILTER_REJECTED = 'category_filter must contain supported notification categories.';

    public function __construct(
        private WebhookDestinationPortInterface $destinations,
    ) {
    }

    /**
     * @throws InvalidInputException when the ID is not a UUID
     */
    public static function id(string $webhookId): Uuid
    {
        try {
            return Uuid::fromString($webhookId);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException('The webhook ID must be a UUID.', previous: $error);
        }
    }

    /**
     * @param string $missing the message when the URL is absent, blank or not a string
     *
     * @throws InvalidInputException when the URL is missing or its destination is not allowed
     */
    public function url(mixed $url, string $missing): string
    {
        if (!is_string($url) || trim($url) === '') {
            throw new InvalidInputException($missing);
        }

        if ($this->destinations->resolve($url) === null) {
            throw new InvalidInputException(self::DESTINATION_REJECTED);
        }

        return $url;
    }

    /**
     * @return list<string>|null
     *
     * @throws InvalidInputException when the filter is neither null nor a list of supported categories
     */
    public static function categoryFilter(mixed $filter): ?array
    {
        if (!NotificationCategoryFilter::isValid($filter)) {
            throw new InvalidInputException(self::CATEGORY_FILTER_REJECTED);
        }

        /** @var list<string>|null $filter */
        return $filter;
    }
}
