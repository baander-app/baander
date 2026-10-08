<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/albums/merge.
 */
#[AsCommand(
    name: 'app:album:merge',
    description: 'Merge a source album into a target album and delete the source.',
)]
final class AlbumMergeCommand extends Command
{
    public function __construct(
        private readonly AlbumPortInterface $albums,
        private readonly AlbumMergePortInterface $merge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'The public ID of the album to keep');
        $this->addArgument('source', InputArgument::REQUIRED, 'The public ID of the album to merge into the target and delete');
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $targetPublicId = PublicId::fromString((string) $input->getArgument('target'));
            $sourcePublicId = PublicId::fromString((string) $input->getArgument('source'));
        } catch (InvalidArgumentException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return Command::INVALID;
        }

        try {
            $target = $this->albums->findByPublicId($targetPublicId);
            $source = $this->albums->findByPublicId($sourcePublicId);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if ($target === null) {
            $io->getErrorStyle()->error('Target album not found.');

            return Command::FAILURE;
        }

        if ($source === null) {
            $io->getErrorStyle()->error('Source album not found.');

            return Command::FAILURE;
        }

        $refused = AdminCommandSupport::confirm($input, $io, sprintf(
            'Merge "%s" (%s) into "%s" (%s)? Its songs move to the target and the source album is deleted.',
            $source->getTitle(),
            $sourcePublicId->toString(),
            $target->getTitle(),
            $targetPublicId->toString(),
        ));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $merged = $this->merge->mergeAlbums($target->getId(), $source->getId());
        } catch (InvalidArgumentException $exception) {
            // The merge rules reject the pair, as the API answers 400.
            $io->getErrorStyle()->error($exception->getMessage());

            return Command::INVALID;
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf(
            'Merged "%s" into "%s" (%s).',
            $source->getTitle(),
            $merged->getTitle(),
            $merged->getPublicId()->toString(),
        ));

        return Command::SUCCESS;
    }
}
