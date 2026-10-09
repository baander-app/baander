<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of PATCH /api/songs/{publicId}.
 */
#[AsCommand(
    name: 'app:song:update',
    description: "Change a song's metadata, or lock and unlock its fields.",
)]
final class SongUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
        private readonly SongPortInterface $songs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-id', InputArgument::REQUIRED, 'The public ID of the song')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The new title')
            ->addOption('track', null, InputOption::VALUE_REQUIRED, 'The new track number')
            ->addOption('disc', null, InputOption::VALUE_REQUIRED, 'The new disc number')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'The new year')
            ->addOption('comment', null, InputOption::VALUE_REQUIRED, 'The new comment')
            ->addOption('lyrics', null, InputOption::VALUE_REQUIRED, 'The new lyrics')
            ->addOption('explicit', null, InputOption::VALUE_NEGATABLE, 'Mark the song explicit, or not explicit with --no-explicit');
        FieldLockOptions::add($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $explicit = $input->getOption('explicit');

        try {
            $song = $this->support->dispatch(new UpdateSongCommand(
                publicId: (string) $input->getArgument('public-id'),
                title: AdminCommandSupport::stringOption($input, 'title'),
                track: AdminCommandSupport::integerOption($input, 'track'),
                disc: AdminCommandSupport::integerOption($input, 'disc'),
                year: AdminCommandSupport::integerOption($input, 'year'),
                comment: AdminCommandSupport::stringOption($input, 'comment'),
                lyrics: AdminCommandSupport::stringOption($input, 'lyrics'),
                explicit: is_bool($explicit) ? $explicit : null,
                lock: FieldLockOptions::read($input, FieldLockOptions::LOCK),
                unlock: FieldLockOptions::read($input, FieldLockOptions::UNLOCK),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        // The same song resource SongController returns, with the artist and album names.
        $resource = SongResource::fromWithMeta(
            $song,
            $this->songs->getArtistNamesForSongs([$song->getId()]),
            $this->songs->getAlbumTitlesByIds([$song->getAlbumId()]),
        );

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $resource);
        }

        $io->success(sprintf('Song "%s" (%s) updated.', $resource['title'], $resource['publicId']));
        if ($resource['lockedFields'] !== []) {
            $io->text('Locked fields: ' . implode(', ', $resource['lockedFields']));
        }

        return Command::SUCCESS;
    }
}
