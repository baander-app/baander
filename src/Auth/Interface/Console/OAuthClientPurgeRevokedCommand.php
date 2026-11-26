<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\PurgeRevokedPersonalAccessClientsCommand;
use App\Auth\Application\DTO\PurgedPersonalAccessClientsDTO;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:oauth:client:purge-revoked',
    description: 'Delete revoked personal access clients and the tokens issued to them.',
)]
final class OAuthClientPurgeRevokedCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Runs the same purge as the daily scheduled job. A revoked personal access client
            can no longer get or use tokens; the purge removes its row and, with it, every
            token, code and pushed authorization request issued to it.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->support->dispatch(new PurgeRevokedPersonalAccessClientsCommand());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($result instanceof PurgedPersonalAccessClientsDTO);

        $io->success(sprintf('Deleted %d revoked personal access client(s).', $result->clients));

        return Command::SUCCESS;
    }
}
