<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\MovieLibraryIngest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ingests a directory of test video files as a movie library, for the e2e transcoding tests.
 *
 * The command finds or creates the library, then runs the scan and catalog handlers in this
 * process, so it works while the web server runs and needs no Messenger consumer.
 */
#[AsCommand(
    name: 'app:e2e:ingest-video',
    description: 'Ingest a directory of test video files as a movie library for e2e tests.',
)]
final class IngestTestVideoCommand extends Command
{
    public function __construct(
        private readonly MovieLibraryIngest $movieLibraryIngest,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Absolute path to a directory of video files')
            ->addArgument('libraryName', InputArgument::OPTIONAL, 'Library name', 'E2E Test Movies')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Library slug', 'e2e-test-movies');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->movieLibraryIngest->ingestMovieLibrary(
            (string) $input->getArgument('libraryName'),
            (string) $input->getArgument('slug'),
            (string) $input->getArgument('path'),
        );

        if ($result->libraryCreated) {
            $io->info(sprintf('Created library "%s" (%s)', $result->libraryName, $result->libraryId));
        }

        if ($result->videoIds === []) {
            $io->warning('No video files were ingested. Is the path correct?');

            return Command::FAILURE;
        }

        sprintf('Ingested %d video(s)', count($result->videoIds)) |> $io->success(...);
        $io->listing($result->videoIds);

        return Command::SUCCESS;
    }
}
