<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:oauth:client:rotate-secret',
    description: 'Give a confidential OAuth client a new secret.',
)]
final class OAuthClientRotateSecretCommand extends Command
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
            $registered = $this->dispatcher->dispatch(new RotateClientSecretCommand(
                OAuthClientMessageDispatcher::publicId((string) $input->getArgument('client-id')),
            ));
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        assert($registered instanceof RegisteredClientDTO);

        $io->success('The client has a new secret. The old secret no longer works.');
        OAuthClientMessageDispatcher::show($io, AdminOAuthClientCredentialsResource::from($registered));

        return Command::SUCCESS;
    }
}
