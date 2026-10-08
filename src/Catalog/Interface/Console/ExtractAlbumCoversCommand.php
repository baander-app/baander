<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/albums/covers/extract.
 *
 * The batch that pages the coverless albums runs inline and is recorded in the job
 * monitor (KTD4); it queues one extraction job per album on the async queue, as the
 * batch does when the web path queues it.
 */
#[AsCommand(
    name: 'app:album:extract-covers',
    description: 'Queue embedded cover art extraction for every album without a cover.',
)]
final class ExtractAlbumCoversCommand extends Command
{
    public function __construct(
        private readonly AlbumPortInterface $albums,
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->text(sprintf('%d album(s) have no cover art. Queuing an extraction job for each...', $this->albums->countCoverlessAlbums()));
            $run = $this->jobMonitor->runInline(new BatchExtractCoversCommand());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf(
            'Queued %d cover extraction job(s) on the async queue. Batch job ID: %s',
            (int) $run->result,
            $run->jobId,
        ));
        $io->text('The queue workers extract the covers. Follow their progress with app:monitor:jobs.');

        return Command::SUCCESS;
    }
}
