<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Query\GetLibraryStatsQuery;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/libraries/{id}/stats. */
#[AsCommand(
    name: 'app:library:stats',
    description: 'Show the content counts of one media library.',
)]
final class LibraryStatsCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('library', InputArgument::REQUIRED, 'Library UUID or slug');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $stats = $this->support->dispatch(new GetLibraryStatsQuery(
                (string) $input->getArgument('library'),
                LibraryReadScope::unrestricted(),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert(is_array($stats));

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $stats);
        }

        $io->horizontalTable(
            ['Songs', 'Albums', 'Artists', 'Genres', 'Total size (bytes)', 'Total duration (seconds)'],
            [[
                (string) $stats['songs'],
                (string) $stats['albums'],
                (string) $stats['artists'],
                (string) $stats['genres'],
                (string) $stats['totalSize'],
                (string) $stats['totalDuration'],
            ]],
        );

        return Command::SUCCESS;
    }
}
