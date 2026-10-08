<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:oauth:client:revoke',
    description: 'Revoke an OAuth client and every token issued to it.',
)]
final class OAuthClientRevokeCommand extends Command
{
    public function __construct(
        private readonly OAuthClientMessageDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED, 'The OAuth client_id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $client = $this->dispatcher->dispatch(new RevokeRegisteredClientCommand(
                OAuthClientMessageDispatcher::publicId((string) $input->getArgument('client-id')),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        $revoked = AdminOAuthClientResource::from($client);

        $io->success(sprintf('Client "%s" and its tokens are revoked.', (string) $revoked['name']));

        return Command::SUCCESS;
    }
}
