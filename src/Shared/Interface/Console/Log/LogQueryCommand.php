<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use App\Shared\Application\LogViewer\LogSqlQuery;
use App\Shared\Application\LogViewer\LogViewerInterface;
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

#[AsCommand(name: 'app:log:query', description: 'Run bounded read-only SQL against a log snapshot')]
final class LogQueryCommand extends Command
{
    public function __construct(private readonly LogViewerInterface $viewer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        LogCommandInput::configure($this, sql: true);
        $this->addArgument('sql', InputArgument::REQUIRED, 'Read-only SQL over logs');
        $this->addOption('allow-partial', null, InputOption::VALUE_NONE, 'Accept explicitly incomplete source coverage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $data = $this->viewer->query(new LogSqlQuery($input->getArgument('sql'), $input->getOption('allow-partial') === true, LogCommandInput::query($input, sql: true)));
            if (AdminCommandSupport::wantsJson($input)) {
                return AdminCommandSupport::json($io, $data);
            }
            $output->writeln(TerminalText::clean(AdminCommandSupport::prettyJson($data)), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable $error) {
            return AdminCommandSupport::fail($io, $error);
        }
    }
}
