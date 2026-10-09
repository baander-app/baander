<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\IssuedWebhookSecret;
use App\Notification\Application\DTO\RotateWebhookSecretCommand;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Application\Service\WebhookInput;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RotateWebhookSecretHandler
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookSecretPortInterface $secrets,
    ) {
    }

    /**
     * @return IssuedWebhookSecret the webhook, with its own signing version, and the new plain secret,
     *                             which is not available again
     *
     * @throws \App\Shared\Application\Exception\InvalidInputException when the ID is not a UUID
     * @throws NotFoundException                                         when no webhook has the ID
     */
    #[AsMessageHandler]
    public function __invoke(RotateWebhookSecretCommand $command): IssuedWebhookSecret
    {
        $id = WebhookInput::id($command->webhookId);
        $secret = bin2hex(random_bytes(32));

        $webhook = $this->webhooks->replaceSecret($id, $this->secrets->encrypt($secret))
            ?? throw new NotFoundException('Webhook not found.');

        return new IssuedWebhookSecret($webhook, $secret);
    }
}
