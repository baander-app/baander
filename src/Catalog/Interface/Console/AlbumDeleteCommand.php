<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Query\Album\AlbumDeletePreview;
use App\Catalog\Application\Query\Album\GetAlbumDeletePreviewQuery;
use App\Catalog\Interface\Resource\AlbumDeletePreviewResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/admin/albums/{publicId} (and DELETE /api/albums/{publicId});
 * --dry-run is the counterpart of GET /api/admin/albums/{publicId}/delete-preview.
 */
#[AsCommand(
    name: 'app:album:delete',
    description: 'Delete an album and every song on it, optionally with the audio files.',
)]
final class AlbumDeleteCommand extends Command
{
    private const string KEEP_COVER = 'keep-cover';

    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'The album public ID');
        CatalogDeleteOutput::addFileOptions($this, 'the song audio files');
        $this->addOption(self::KEEP_COVER, null, InputOption::VALUE_NONE, 'Keep the cover image');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $deleteFiles = $input->getOption(CatalogDeleteOutput::DELETE_FILES) === true;

        if ($input->getOption(CatalogDeleteOutput::DRY_RUN) === true) {
            return $this->preview($input, $io, $publicId, $deleteFiles);
        }

        $refused = CatalogDeleteOutput::refuseFilesWithoutForce($input, $io)
            ?? AdminCommandSupport::confirm($input, $io, sprintf('Delete album %s and every song on it? This cannot be undone.', $publicId));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $result = $this->support->dispatch(new DeleteAlbumCommand(
                publicId: $publicId,
                deleteFiles: $deleteFiles,
                deleteCover: $input->getOption(self::KEEP_COVER) !== true,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return CatalogDeleteOutput::result($input, $io, $result, sprintf('Album %s', $publicId));
    }

    private function preview(InputInterface $input, SymfonyStyle $io, string $publicId, bool $deleteFiles): int
    {
        try {
            $preview = $this->support->dispatch(new GetAlbumDeletePreviewQuery($publicId, $deleteFiles));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($preview instanceof AlbumDeletePreview);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, AlbumDeletePreviewResource::from($preview));
        }

        $io->definitionList(
            ['Album' => sprintf('%s (%s)', $preview->title, $preview->publicId)],
            ['Songs' => (string) $preview->songCount],
            ['Size' => Helper::formatMemory($preview->totalSize)],
            ['Cover image' => $preview->coverImageId ?? 'none'],
            ['Playlists losing songs' => $preview->playlistNames !== [] ? implode(', ', $preview->playlistNames) : 'none'],
        );
        if ($preview->fileDeletion !== null) {
            CatalogDeleteOutput::fileChecks($io, $preview->fileDeletion);
        }
        $io->note('Dry run: nothing was changed.');

        return Command::SUCCESS;
    }
}
