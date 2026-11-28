<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Shared\Application\Command\UpdateSystemSettingsCommand;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[AsCommand(name: 'app:metadata:provider:set', description: 'Switch a metadata provider on or off for the server.')]
final class MetadataProviderSetCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly MetadataAdminPortInterface $metadata,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::REQUIRED, 'Provider ID from app:metadata:providers --json')
            ->addArgument('enabled', InputArgument::REQUIRED, 'true to enable or false to disable');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $provider = MetadataProvider::tryFrom((string) $input->getArgument('provider'))
                ?? throw new InvalidInputException('Unknown metadata provider.');
            $enabled = match ((string) $input->getArgument('enabled')) {
                'true' => true,
                'false' => false,
                default => throw new InvalidInputException('The enabled argument must be true or false.'),
            };
            $this->bus->dispatch(new UpdateSystemSettingsCommand([$provider->settingKey() => $enabled]));
            $state = $this->metadata->getProvider($provider);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $state);
        }
        $io->success(sprintf('%s is now %s.', $state['name'], $state['enabled'] ? 'enabled' : 'disabled'));

        return Command::SUCCESS;
    }
}
