<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\UpdateWebhookCommand;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of PUT /api/webhooks/{id}. */
#[AsCommand(
    name: 'app:webhook:update',
    description: 'Change a webhook\'s URL or the notification categories it receives.',
)]
final class WebhookUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The webhook UUID')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The new destination URL; it must resolve only to allowed addresses')
            ->addOption('category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Deliver only this notification category; repeat for several. Replaces the current categories')
            ->addOption('all-categories', null, InputOption::VALUE_NONE, 'Deliver every notification category, clearing the category filter');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = AdminCommandSupport::stringOption($input, 'url');
        $categories = WebhookConsole::categories($input);
        $allCategories = $input->getOption('all-categories') === true;

        try {
            if ($allCategories && $categories !== null) {
                throw new InvalidInputException('Give --category or --all-categories, not both.');
            }

            $webhook = $this->support->dispatch(new UpdateWebhookCommand(
                webhookId: (string) $input->getArgument('id'),
                changesUrl: $url !== null,
                url: $url,
                changesCategoryFilter: $allCategories || $categories !== null,
                categoryFilter: $categories,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $updated = WebhookResource::from($webhook);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $updated);
        }

        $io->success(sprintf(
            'Webhook %s now delivers %s to %s.',
            $updated['id'],
            $updated['category_filter'] === null ? 'every category' : implode(', ', $updated['category_filter']),
            $updated['url'],
        ));

        return Command::SUCCESS;
    }
}
