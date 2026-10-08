<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/lyrics/coverage. */
#[AsCommand(
    name: 'app:lyrics:coverage',
    description: 'Show how many tracks have lyrics, and the lyrics by source.',
)]
final class LyricsCoverageCommand extends Command
{
    public function __construct(
        private readonly LyricsAdminPortInterface $lyricsAdmin,
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
            $coverage = $this->lyricsAdmin->getCoverage();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $coverage);
        }

        $io->table(['Metric', 'Value'], [
            ['Total tracks', $coverage['totalTracks']],
            ['Tracks with lyrics', $coverage['tracksWithLyrics']],
            ['Tracks without lyrics', $coverage['tracksWithoutLyrics']],
            ['Coverage', sprintf('%.2f %%', $coverage['coveragePercentage'])],
        ]);

        if ($coverage['bySource'] === []) {
            $io->text('No lyrics are stored.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($coverage['bySource'] as $source => $count) {
            $rows[] = [$source, $count];
        }
        $io->table(['Source', 'Tracks'], $rows);

        return Command::SUCCESS;
    }
}
