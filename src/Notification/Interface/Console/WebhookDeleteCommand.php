<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\DeleteWebhookCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of DELETE /api/webhooks/{id}. */
#[AsCommand(
    name: 'app:webhook:delete',
    description: 'Delete a webhook.',
)]
final class WebhookDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The webhook UUID');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        $refused = AdminCommandSupport::confirm($input, $io, sprintf('Delete the webhook %s? Nothing is delivered to it afterwards.', $id));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->support->dispatch(new DeleteWebhookCommand($id));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Webhook %s deleted.', $id));
        }

        return Command::SUCCESS;
    }
}
