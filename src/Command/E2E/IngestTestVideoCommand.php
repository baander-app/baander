<?php

declare(strict_types=1);

namespace App\Command\E2E;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\CommandHandler\CreateLibraryHandler;
use App\Library\Application\CommandHandler\ScanLibraryHandler;
use App\Library\Application\MovieScanner;
use App\Library\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ingest a test video file into the catalog for e2e transcoding tests.
 *
 * This command runs the scan and catalog handlers synchronously so it can be
 * executed from the CLI while the Swoole server is running. It does not rely on
 * Messenger transports for the ingest step.
 */
#[AsCommand(
    name: 'app:e2e:ingest-video',
    description: 'Ingest a test video file into the catalog for e2e tests.',
)]
final class IngestTestVideoCommand extends Command
{
    public function __construct(
        private readonly LibraryRepository $libraryRepository,
        private readonly CreateLibraryHandler $createLibraryHandler,
        private readonly ScanLibraryHandler $scanHandler,
        private readonly MovieScanner $movieScanner,
        private readonly FilesDiscoveredHandler $catalogHandler,
        private readonly VideoRepositoryInterface $videoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('libraryName', InputArgument::OPTIONAL, 'Library name', 'E2E Test Movies')
            ->addArgument('path', InputArgument::OPTIONAL, 'Absolute path to the video file or directory', '/home/martin/Videos')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Library slug', 'e2e-test-movies');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = (string) $input->getArgument('libraryName');
        $path = (string) $input->getArgument('path');
        $slug = new LibrarySlug((string) $input->getArgument('slug'));

        $library = $this->libraryRepository->findBySlug($slug);
        if ($library === null) {
            $library = ($this->createLibraryHandler)(new CreateLibraryCommand(
                name: $name,
                slug: $slug,
                path: new LibraryPath($path),
                type: LibraryType::Movie,
                filesystemType: FilesystemType::Local,
                sortOrder: 0,
            ));
            $io->info(sprintf('Created library "%s" (%s)', $name, $library->getId()->toString()));
        }

        // Run the scan synchronously. The scan handler emits FilesDiscovered messages
        // to the async transport, but we invoke the catalog handler directly below.
        ($this->scanHandler)(new \App\Library\Application\Command\ScanLibraryCommand(librarySlug: $slug));

        $library = $this->libraryRepository->findBySlug($slug);
        if ($library === null) {
            $io->error('Library disappeared during scan.');

            return Command::FAILURE;
        }

        $scanResult = $this->movieScanner->scan($library, true);
        $videoIds = [];

        foreach ($scanResult->directories as $directory => $files) {
            ($this->catalogHandler)(new \App\Library\Application\Message\FilesDiscovered(
                libraryId: $library->getId(),
                libraryType: $library->getType()->value,
                directory: $directory,
                files: $files,
            ));

            foreach ($files as $file) {
                $video = $this->videoRepository->findByHash($file->hash);
                if ($video !== null) {
                    $videoIds[] = $video->getId()->toString();
                }
            }
        }

        if ($videoIds === []) {
            $io->warning('No video files were ingested. Is the path correct?');

            return Command::FAILURE;
        }

        $io->success(sprintf('Ingested %d video(s)', count($videoIds)));
        $io->listing($videoIds);

        return Command::SUCCESS;
    }
}
