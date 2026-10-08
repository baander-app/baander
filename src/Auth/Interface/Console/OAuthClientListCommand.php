<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:oauth:client:list',
    description: 'List OAuth clients other than personal access clients.',
)]
final class OAuthClientListCommand extends Command
{
    public function __construct(
        private readonly OAuthClientMessageDispatcher $dispatcher,
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
            $clients = $this->dispatcher->dispatch(new ListRegisteredClientsQuery());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert(is_array($clients));

        return AdminCommandSupport::list(
            $input,
            $io,
            AdminOAuthClientResource::collection($clients),
            ['Client ID', 'Name', 'Type', 'Redirect URIs', 'Revoked', 'Created'],
            static fn (array $client): array => [
                $client['clientId'],
                $client['name'],
                $client['type'],
                $client['redirectUris'] === [] ? '-' : implode("\n", $client['redirectUris']),
                $client['revoked'] ? 'yes' : 'no',
                $client['createdAt'],
            ],
            'No OAuth clients are registered.',
        );
    }
}
