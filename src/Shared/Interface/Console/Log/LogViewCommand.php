<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use App\Shared\Application\LogViewer\LogViewerInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:log:view', description: 'Browse current and archived logs in a terminal')]
final class LogViewCommand extends Command implements SignalableCommandInterface
{
    public function __construct(private readonly LogViewerInterface $viewer, private readonly LogTerminalViewer $terminal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        LogCommandInput::configure($this);
        $this->addOption('plain', null, InputOption::VALUE_NONE, 'Print one bounded result without terminal controls');
        $this->addOption('detail', null, InputOption::VALUE_REQUIRED, 'Inspect an opaque record ID');
        $this->setHelp('Keys: arrows/j/k, PgUp/PgDn, g/G, / search, n/N matches, s sources, f levels, c comma-separated channels (match any), r correlation, t since, u until, : SQL, a partial SQL opt-in, F follow, Enter detail, ? help, q quit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $query = LogCommandInput::query($input);
            $detail = AdminCommandSupport::stringOption($input, 'detail');
            if ($detail === null && !AdminCommandSupport::wantsJson($input) && $input->getOption('plain') !== true && $input->isInteractive() && defined('STDIN') && defined('STDOUT') && stream_isatty(STDIN) && stream_isatty(STDOUT)) {
                return $this->terminal->run($query, $output);
            }
            $data = $detail !== null ? $this->viewer->detail($detail) : $this->viewer->entries($query);
            if (AdminCommandSupport::wantsJson($input)) {
                return AdminCommandSupport::json($io, $data);
            }
            $output->writeln(LogTerminalText::clean(AdminCommandSupport::prettyJson($data)), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable $error) {
            return AdminCommandSupport::fail($io, $error);
        }
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->terminal->stop();

        return false;
    }
}
