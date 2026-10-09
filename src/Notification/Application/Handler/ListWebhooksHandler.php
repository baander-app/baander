<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\ListWebhooksQuery;
use App\Notification\Application\DTO\WebhookView;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ListWebhooksHandler
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
    ) {
    }

    /** @return list<WebhookView> */
    #[AsMessageHandler]
    public function __invoke(ListWebhooksQuery $query): array
    {
        return $this->webhooks->findAll();
    }
}
