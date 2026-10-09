<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\ApplyLyricsCommand;
use App\Lyrics\Application\Service\LyricsSongResolver;
use App\Lyrics\Interface\Resource\LyricsResource;
use App\Shared\Application\Exception\InvalidInputException;
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
 * The CLI counterpart of POST /api/lyrics/search/{resultId}/apply.
 *
 * Stores an LRCLIB search result, found with app:lyrics:search, as the lyrics of a song in
 * any library that has none.
 */
#[AsCommand(
    name: 'app:lyrics:apply',
    description: 'Store an LRCLIB search result as the lyrics of a song that has none.',
)]
final class LyricsApplyCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
        private readonly LyricsSongResolver $songs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('result-id', InputArgument::REQUIRED, 'The LRCLIB result ID, as app:lyrics:search lists it')
            ->addArgument('song', InputArgument::REQUIRED, 'The song public ID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $resultId = filter_var($input->getArgument('result-id'), FILTER_VALIDATE_INT);
            if ($resultId === false) {
                throw new InvalidInputException('The result ID must be an integer.');
            }
            $songId = $this->songs->songId((string) $input->getArgument('song'), LibraryReadScope::unrestricted());
            $data = LyricsResource::from($this->support->dispatch(new ApplyLyricsCommand($resultId, $songId)));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->success(sprintf('LRCLIB result %d is now the lyrics of the song.', $resultId));
        LyricsOutput::show($io, $data);

        return Command::SUCCESS;
    }
}
