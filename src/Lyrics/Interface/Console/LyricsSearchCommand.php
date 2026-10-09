<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Query\SearchLyricsQuery;
use App\Lyrics\Interface\Resource\LrclibSearchResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/lyrics/search.
 *
 * Lists the LRCLIB results with the IDs that app:lyrics:apply takes.
 */
#[AsCommand(
    name: 'app:lyrics:search',
    description: 'Search LRCLIB for lyrics and list the results with their IDs.',
)]
final class LyricsSearchCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::IS_ARRAY, 'The search keywords, such as the title and the artist');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $keywords */
        $keywords = $input->getArgument('query');

        try {
            $results = $this->support->dispatch(new SearchLyricsQuery(implode(' ', $keywords)));
            assert(is_array($results));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            LrclibSearchResource::collection($results),
            ['ID', 'Track', 'Artist', 'Album', 'Duration', 'Synced', 'Instrumental'],
            static fn(array $result): array => [
                $result['id'],
                $result['trackName'],
                $result['artistName'],
                $result['albumName'],
                self::duration($result['duration']),
                AdminCommandSupport::yesNo(is_string($result['syncedLyrics']) && $result['syncedLyrics'] !== ''),
                AdminCommandSupport::yesNo($result['instrumental'] === true),
            ],
            'LRCLIB has no lyrics matching the query.',
        );
    }

    private static function duration(mixed $seconds): string
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return '-';
        }

        $whole = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
