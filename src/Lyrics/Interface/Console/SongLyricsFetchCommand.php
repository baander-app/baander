<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LyricsFetchResult;
use App\Lyrics\Application\Service\LyricsSongResolver;
use App\Lyrics\Interface\Resource\LyricsResource;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/songs/{publicId}/lyrics/fetch.
 *
 * Runs FetchLyricsCommand in this process, as the API does, for a song in any library.
 */
#[AsCommand(
    name: 'app:song:lyrics:fetch',
    description: 'Fetch the lyrics of one song from LRCLIB and store them.',
)]
final class SongLyricsFetchCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
        private readonly LyricsSongResolver $songs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('song', InputArgument::REQUIRED, 'The song public ID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $songId = $this->songs->songId((string) $input->getArgument('song'), LibraryReadScope::unrestricted());
            $result = $this->support->dispatch(new FetchLyricsCommand($songId));
            assert($result instanceof LyricsFetchResult);
            $lyrics = $result->lyricsOrFail();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $data = $lyrics === null ? [] : LyricsResource::from($lyrics);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        if ($data === []) {
            $io->text('No lyrics were found for this song; nothing was stored.');

            return Command::SUCCESS;
        }

        LyricsOutput::show($io, $data);

        return Command::SUCCESS;
    }
}
