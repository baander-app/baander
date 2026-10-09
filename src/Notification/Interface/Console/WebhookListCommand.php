<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\ListWebhooksQuery;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/webhooks/. */
#[AsCommand(
    name: 'app:webhook:list',
    description: 'List the configured webhooks, without their secrets.',
)]
final class WebhookListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $webhooks = $this->support->dispatch(new ListWebhooksQuery());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            WebhookResource::collection($webhooks),
            ['ID', 'URL', 'Categories', 'Created', 'Updated'],
            static fn (array $webhook): array => [
                $webhook['id'],
                $webhook['url'],
                $webhook['category_filter'] === null ? 'all' : implode(', ', $webhook['category_filter']),
                $webhook['created_at'],
                $webhook['updated_at'],
            ],
            'No webhooks are configured.',
        );
    }
}
