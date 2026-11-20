<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use App\Shared\Application\LogViewer\LogViewerInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:log:sources', description: 'List admitted log sources and collection coverage')]
final class LogSourcesCommand extends Command
{
    public function __construct(private readonly LogViewerInterface $viewer)
    {
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
            $data = $this->viewer->sources();
            if (AdminCommandSupport::wantsJson($input)) {
                return AdminCommandSupport::json($io, $data);
            }
            $output->writeln(LogTerminalText::clean(AdminCommandSupport::prettyJson($data)), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable $error) {
            return AdminCommandSupport::fail($io, $error);
        }
    }
}
