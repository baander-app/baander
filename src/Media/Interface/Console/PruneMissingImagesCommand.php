<?php

declare(strict_types=1);

namespace App\Media\Interface\Console;

use App\Media\Application\Port\MediaAdminPortInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:images:prune-missing',
    description: 'Remove image records whose files no longer exist on disk.',
)]
final class PruneMissingImagesCommand extends Command
{
    public function __construct(
        private readonly MediaAdminPortInterface $mediaAdmin,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check first (dry-run)
        $result = $this->mediaAdmin->checkMissingImages();

        if ($result['missingCount'] === 0) {
            $io->success(sprintf('All %d image files are present. No pruning needed.', $result['totalImages']));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Found %d of %d images with missing files.',
            $result['missingCount'],
            $result['totalImages'],
        ));

        foreach ($result['missingImages'] as $img) {
            $io->text(sprintf('  ✗ %s (%s: %s)', $img['id'], $img['type'], $img['path']));
        }

        // Dispatch async prune job
        $this->mediaAdmin->pruneMissingImages();
        $io->success('Prune job dispatched. Check job monitor for progress.');

        return Command::SUCCESS;
    }
}
