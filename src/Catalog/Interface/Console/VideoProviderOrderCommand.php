<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Interface\Resource\LibraryProviderOrderResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Shared\Interface\Console\TerminalText;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:video:providers:order', description: 'Read or replace the metadata provider order of a TV or movie library.')]
final class VideoProviderOrderCommand extends Command
{
    public function __construct(private readonly LibraryProviderOrderService $orders)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'Library public ID or slug')
            ->addOption('providers', null, InputOption::VALUE_REQUIRED, 'Complete replacement order as comma-separated provider IDs');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $library = (string) $input->getArgument('library');
            $replacement = AdminCommandSupport::stringOption($input, 'providers');
            if ($replacement === null) {
                $order = $this->orders->getByIdentifier($library);
            } else {
                $providers = explode(',', $replacement);
                $providers = array_map(trim(...), $providers);
                $order = $this->orders->setByIdentifier($library, $providers);
            }
            $data = LibraryProviderOrderResource::from($order);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $rows = array_map(static fn (string $provider): array => [$provider], $data['providers']);
        $io->table(['Provider'], TerminalText::literalRows($rows));

        return Command::SUCCESS;
    }
}
