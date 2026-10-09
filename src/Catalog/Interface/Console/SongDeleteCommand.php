<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\Query\Song\GetSongDeletePreviewQuery;
use App\Catalog\Application\Query\Song\SongDeletePreview;
use App\Catalog\Interface\Resource\SongDeletePreviewResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/admin/songs/{publicId} (and DELETE /api/songs/{publicId});
 * --dry-run is the counterpart of GET /api/admin/songs/{publicId}/delete-preview.
 */
#[AsCommand(
    name: 'app:song:delete',
    description: 'Delete a song, optionally with its audio file.',
)]
final class SongDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'The song public ID');
        CatalogDeleteOutput::addFileOptions($this, 'the audio file');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $deleteFile = $input->getOption(CatalogDeleteOutput::DELETE_FILES) === true;

        if ($input->getOption(CatalogDeleteOutput::DRY_RUN) === true) {
            return $this->preview($input, $io, $publicId, $deleteFile);
        }

        $refused = CatalogDeleteOutput::refuseFilesWithoutForce($input, $io)
            ?? AdminCommandSupport::confirm($input, $io, sprintf('Delete song %s? This cannot be undone.', $publicId));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $result = $this->support->dispatch(new DeleteSongCommand(publicId: $publicId, deleteFile: $deleteFile));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return CatalogDeleteOutput::result($input, $io, $result, sprintf('Song %s', $publicId));
    }

    private function preview(InputInterface $input, SymfonyStyle $io, string $publicId, bool $deleteFile): int
    {
        try {
            $preview = $this->support->dispatch(new GetSongDeletePreviewQuery($publicId, $deleteFile));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($preview instanceof SongDeletePreview);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, SongDeletePreviewResource::from($preview));
        }

        $io->definitionList(
            ['Song' => sprintf('%s (%s)', $preview->title, $preview->publicId)],
            ['Album' => $preview->album !== null ? sprintf('%s (%s)', $preview->album['title'], $preview->album['id']) : 'none'],
            ['File' => sprintf('%s (%s)', $preview->path, Helper::formatMemory($preview->size))],
            ['Playlists losing it' => $preview->playlistNames !== [] ? implode(', ', $preview->playlistNames) : 'none'],
        );
        if ($preview->fileDeletion !== null) {
            CatalogDeleteOutput::fileChecks($io, $preview->fileDeletion);
        }
        $io->note('Dry run: nothing was changed.');

        return Command::SUCCESS;
    }
}
