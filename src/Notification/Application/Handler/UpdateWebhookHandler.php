<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\UpdateWebhookCommand;
use App\Notification\Application\DTO\WebhookView;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Notification\Application\Service\WebhookInput;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class UpdateWebhookHandler
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookInput $input,
    ) {
    }

    /**
     * @throws \App\Shared\Application\Exception\InvalidInputException when the ID, the URL or the category filter is rejected; nothing changes
     * @throws NotFoundException                                         when no webhook has the ID
     */
    #[AsMessageHandler]
    public function __invoke(UpdateWebhookCommand $command): WebhookView
    {
        $id = WebhookInput::id($command->webhookId);
        $webhook = $this->webhooks->find($id) ?? throw self::notFound();

        $categoryFilter = $command->changesCategoryFilter
            ? WebhookInput::categoryFilter($command->categoryFilter)
            : $webhook->categoryFilter;
        $url = $command->changesUrl
            ? $this->input->url($command->url, 'URL must be a non-empty string.')
            : $webhook->url;

        return $this->webhooks->update($id, $url, $categoryFilter) ?? throw self::notFound();
    }

    private static function notFound(): NotFoundException
    {
        return new NotFoundException('Webhook not found.');
    }
}
