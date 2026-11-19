<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\ReplaceClientKeysCommand;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/oauth/clients/{clientId}/keys. */
#[AsCommand(
    name: 'app:oauth:client:replace-keys',
    description: 'Replace the public keys of an OAuth client that authenticates with private_key_jwt.',
)]
final class OAuthClientReplaceKeysCommand extends Command
{
    public function __construct(
        private readonly OAuthClientMessageDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED, 'The OAuth client_id');
        OAuthClientMessageDispatcher::addKeySourceOptions($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            [$jwks, $jwksUri] = OAuthClientMessageDispatcher::keySource($input);
            $replaced = $this->dispatcher->dispatch(new ReplaceClientKeysCommand(
                OAuthClientMessageDispatcher::publicId((string) $input->getArgument('client-id')),
                $jwks,
                $jwksUri,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($replaced instanceof Client);
        $client = AdminOAuthClientResource::from($replaced);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $client);
        }

        $io->success('The client has new keys. Assertions signed with the old keys no longer work.');
        OAuthClientMessageDispatcher::show($io, $client);

        return Command::SUCCESS;
    }
}
