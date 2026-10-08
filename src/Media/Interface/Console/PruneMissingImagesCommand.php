<?php

declare(strict_types=1);

namespace App\Media\Interface\Console;

use App\Media\Application\Command\PruneMissingImagesCommand as PruneMissingImagesMessage;
use App\Media\Application\Port\MediaAdminPortInterface;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/media/prune-missing, and with `--dry-run` of
 * GET /api/admin/media/missing-check.
 *
 * The prune runs inline and is recorded in the job monitor (KTD4): the web path queues it
 * on the Swoole task workers, which a console process cannot reach.
 */
#[AsCommand(
    name: 'app:image:prune-missing',
    description: 'Delete the image records whose files no longer exist in storage.',
)]
final class PruneMissingImagesCommand extends Command
{
    private const string DRY_RUN_OPTION = 'dry-run';

    public function __construct(
        private readonly MediaAdminPortInterface $mediaAdmin,
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(self::DRY_RUN_OPTION, null, InputOption::VALUE_NONE, 'List the images whose files are missing and delete nothing');
        AdminCommandSupport::addJsonOption($this);
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption(self::DRY_RUN_OPTION) === true) {
            return $this->check($input, $io);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            $io->getErrorStyle()->error('--json prints the missing-image check and needs --dry-run.');

            return Command::INVALID;
        }

        $refused = AdminCommandSupport::confirm(
            $input,
            $io,
            'Delete every image record whose file no longer exists in storage? This cannot be undone.',
        );
        if ($refused !== null) {
            return $refused;
        }

        $io->text('Checking every image file and deleting the records of missing ones...');

        try {
            $run = $this->jobMonitor->runInline(new PruneMissingImagesMessage());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('Deleted %d image record(s) with missing files. Job ID: %s', (int) $run->result, $run->jobId));

        return Command::SUCCESS;
    }

    private function check(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $report = $this->mediaAdmin->checkMissingImages();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $report);
        }

        if ($report['missingCount'] === 0) {
            $io->success(sprintf('All %d image files are present. Nothing would be deleted.', $report['totalImages']));

            return Command::SUCCESS;
        }

        $io->table(
            ['Image ID', 'Type', 'Path'],
            array_map(
                static fn (array $image): array => [$image['id'], $image['type'], $image['path']],
                $report['missingImages'],
            ),
        );
        $io->warning(sprintf(
            '%d of %d images have missing files. Nothing was deleted; run the command without --dry-run to delete their records.',
            $report['missingCount'],
            $report['totalImages'],
        ));

        return Command::SUCCESS;
    }
}
