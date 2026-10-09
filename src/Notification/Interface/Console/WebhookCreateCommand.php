<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\CreateWebhookCommand;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/webhooks/. */
#[AsCommand(
    name: 'app:webhook:create',
    description: 'Add a webhook and print its signing secret, which is shown only once.',
)]
final class WebhookCreateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'The destination URL; it must resolve only to allowed addresses')
            ->addOption('category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Deliver only this notification category; repeat for several. Without it, every category is delivered');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $categories = WebhookConsole::categories($input);

        try {
            $issued = $this->support->dispatch(new CreateWebhookCommand($input->getArgument('url'), $categories));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $created = WebhookResource::created($issued);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $created);
        }

        $io->success(sprintf('Webhook %s created for %s.', $created['id'], $created['url']));
        WebhookConsole::printSecret($io, $issued);

        return Command::SUCCESS;
    }
}
