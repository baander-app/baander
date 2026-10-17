<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\DeleteWebhookCommand;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Notification\Application\Service\WebhookInput;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class DeleteWebhookHandler
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
    ) {
    }

    /**
     * @throws \App\Shared\Application\Exception\InvalidInputException when the ID is not a public ID
     * @throws NotFoundException                                         when no webhook has the ID
     */
    #[AsMessageHandler]
    public function __invoke(DeleteWebhookCommand $command): void
    {
        $webhook = $this->webhooks->findByPublicId(WebhookInput::id($command->webhookId))
            ?? throw new NotFoundException('Webhook not found.');
        if (!$this->webhooks->delete($webhook->id)) {
            throw new NotFoundException('Webhook not found.');
        }
    }
}
