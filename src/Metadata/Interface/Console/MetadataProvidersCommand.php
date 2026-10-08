<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/metadata/providers. */
#[AsCommand(
    name: 'app:metadata:providers',
    description: 'List the metadata providers and whether each one is configured.',
)]
final class MetadataProvidersCommand extends Command
{
    public function __construct(
        private readonly MetadataAdminPortInterface $metadataAdmin,
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
            $providers = $this->metadataAdmin->getProviders();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            array_values($providers),
            ['Provider', 'Enabled', 'Configured'],
            static fn (array $provider): array => [
                $provider['name'],
                AdminCommandSupport::yesNo($provider['enabled'] === true),
                AdminCommandSupport::yesNo($provider['configured'] === true),
            ],
            'No metadata providers are registered.',
        );
    }
}
