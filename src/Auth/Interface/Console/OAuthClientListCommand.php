<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $clients = $this->dispatcher->dispatch(new ListRegisteredClientsQuery());
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        assert(is_array($clients));

        if ($clients === []) {
            $io->text('No OAuth clients are registered.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Client ID', 'Name', 'Type', 'Redirect URIs', 'Revoked', 'Created'],
            array_map(static fn (array $client): array => [
                $client['clientId'],
                $client['name'],
                $client['type'],
                $client['redirectUris'] === [] ? '-' : implode("\n", $client['redirectUris']),
                $client['revoked'] ? 'yes' : 'no',
                $client['createdAt'],
            ], AdminOAuthClientResource::collection($clients)),
        );

        return Command::SUCCESS;
    }
}
