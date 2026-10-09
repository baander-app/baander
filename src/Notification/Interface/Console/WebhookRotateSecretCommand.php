<?php

declare(strict_types=1);

namespace App\Notification\Interface\Console;

use App\Notification\Application\DTO\RotateWebhookSecretCommand;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/webhooks/{id}/rotate-secret. */
#[AsCommand(
    name: 'app:webhook:rotate-secret',
    description: 'Replace a webhook\'s signing secret and print the new one, which is shown only once.',
)]
final class WebhookRotateSecretCommand extends Command
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

        $refused = AdminCommandSupport::confirm($input, $io, sprintf(
            'Rotate the signing secret of webhook %s? The current secret stops working at once, so the receiver needs the new one.',
            $id,
        ));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $issued = $this->support->dispatch(new RotateWebhookSecretCommand($id));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, WebhookResource::rotated($issued));
        }

        $io->success(sprintf('The signing secret of webhook %s was replaced.', $id));
        WebhookConsole::printSecret($io, $issued);

        return Command::SUCCESS;
    }
}
