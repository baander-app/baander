<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\CreateWebhookCommand;
use App\Notification\Application\DTO\IssuedWebhookSecret;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Application\Service\WebhookInput;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CreateWebhookHandler
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookInput $input,
        private WebhookSecretPortInterface $secrets,
    ) {
    }

    /**
     * @return IssuedWebhookSecret the new webhook with its plain secret, which is not available again
     *
     * @throws \App\Shared\Application\Exception\InvalidInputException when the URL or the category filter is rejected
     */
    #[AsMessageHandler]
    public function __invoke(CreateWebhookCommand $command): IssuedWebhookSecret
    {
        $url = $this->input->url($command->url, 'URL is required.');
        $categoryFilter = WebhookInput::categoryFilter($command->categoryFilter);
        $secret = bin2hex(random_bytes(32));

        $webhook = $this->webhooks->add(Uuid::generate(), $url, $categoryFilter, $this->secrets->encrypt($secret));

        return new IssuedWebhookSecret($webhook, $secret);
    }
}
