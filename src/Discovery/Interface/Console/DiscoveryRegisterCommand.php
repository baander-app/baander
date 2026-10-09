<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Console;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Interface\Resource\ServerInstanceResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/discovery/register. */
#[AsCommand(
    name: 'app:discovery:register',
    description: 'Register a self-hosted server for discovery and pairing.',
)]
final class DiscoveryRegisterCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'The server\'s base URL')
            ->addArgument('name', InputArgument::REQUIRED, 'The server\'s display name')
            ->addArgument('version', InputArgument::REQUIRED, 'The server\'s version');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $server = ServerInstanceResource::from($this->support->dispatch(new RegisterServerCommand(
                serverUrl: (string) $input->getArgument('url'),
                name: (string) $input->getArgument('name'),
                version: (string) $input->getArgument('version'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $server);
        }

        $io->success(sprintf('Server %s is registered with the public ID %s.', $server['serverUrl'], $server['publicId']));

        return Command::SUCCESS;
    }
}
