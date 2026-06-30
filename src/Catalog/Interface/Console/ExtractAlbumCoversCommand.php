<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:albums:extract-covers',
    description: 'Extract embedded cover art for all albums that are missing one.',
)]
final class ExtractAlbumCoversCommand extends Command
{
    public function __construct(
        private readonly AlbumRepositoryInterface $albumRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $total = $this->albumRepository->countCoverlessAlbums();

        if ($total === 0) {
            $io->info('All albums already have cover art. Nothing to do.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d album(s) without cover art.', $total));

        $limit = 500;
        $offset = 0;
        $dispatched = 0;

        while (true) {
            $ids = $this->albumRepository->findCoverlessAlbumIds($limit, $offset);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $albumId) {
                $this->bus->dispatch(new ExtractAlbumCoverCommand($albumId));
                ++$dispatched;
            }

            $io->text(sprintf('  Dispatched %d / %d', $dispatched, $total));
            $offset += $limit;
        }

        $io->success(sprintf('Dispatched %d cover extraction job(s).', $dispatched));

        return Command::SUCCESS;
    }
}
