<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidateRelease;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Interface\Resource\MatchCandidatesResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/metadata/match/{type}/{publicId}/candidates.
 *
 * Lists the MusicBrainz candidates for an album, song or artist with the IDs that
 * app:metadata:match:compare takes.
 */
#[AsCommand(
    name: 'app:metadata:match:search',
    description: 'List the MusicBrainz candidates for an album, song or artist, best first.',
)]
final class MusicBrainzMatchSearchCommand extends Command
{
    /** How many releases of a recording the table names before it counts the rest. */
    private const int RELEASES_SHOWN = 3;

    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        MatchItemArguments::add($this);
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Search this title, or for an artist this name, instead of the item\'s own')
            ->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Search this artist instead of the item\'s primary artist; ignored for an artist')
            ->addOption('no-artist', null, InputOption::VALUE_NONE, 'Search by the title alone, without an artist');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $candidates = $this->support->dispatch(new SearchMatchCandidatesQuery(
                type: MatchItemArguments::type($input),
                publicId: MatchItemArguments::publicId($input),
                title: AdminCommandSupport::stringOption($input, 'title'),
                artist: self::artistOption($input),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($candidates instanceof MatchCandidates);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, MatchCandidatesResource::from($candidates));
        }

        $noun = MusicBrainzMatchOutput::candidateNoun($candidates->type);
        if ($candidates->candidates === []) {
            $io->text(sprintf('MusicBrainz has no %s that fits.', $noun));

            return Command::SUCCESS;
        }

        $io->text(self::heading($candidates, $noun));
        $io->table(...self::table($candidates));
        $io->text(sprintf('Compare one with: app:metadata:match:compare %s %s <ID>', $candidates->type->value, $candidates->publicId));

        return Command::SUCCESS;
    }

    /**
     * The artist option as the candidates route's `artist` parameter: absent for the item's own
     * artist, empty to search by the title alone.
     *
     * @throws InvalidInputException when both --artist and --no-artist are given
     */
    private static function artistOption(InputInterface $input): ?string
    {
        $artist = AdminCommandSupport::stringOption($input, 'artist');
        if ($input->getOption('no-artist') !== true) {
            return $artist;
        }

        return $artist === null ? '' : throw new InvalidInputException('Give --artist or --no-artist, not both.');
    }

    private static function heading(MatchCandidates $candidates, string $noun): string
    {
        $by = $candidates->artist === null ? '' : sprintf(' by "%s"', $candidates->artist);

        return sprintf('MusicBrainz %ss for "%s"%s, best first:', $noun, $candidates->title, $by);
    }

    /** @return array{list<string>, list<list<string|int>>} the headers and rows */
    private static function table(MatchCandidates $candidates): array
    {
        return match ($candidates->type) {
            MatchItemType::Album => [
                ['ID', 'Score', 'Title', 'Artist', 'Date', 'Country', 'Label', 'Format', 'Tracks', 'Cover'],
                array_map(self::release(...), self::of(ReleaseCandidate::class, $candidates->candidates)),
            ],
            MatchItemType::Song => [
                ['ID', 'Score', 'Title', 'Artist', 'Length', 'Releases'],
                array_map(self::recording(...), self::of(RecordingCandidate::class, $candidates->candidates)),
            ],
            MatchItemType::Artist => [
                ['ID', 'Score', 'Name', 'Disambiguation', 'Type', 'Country', 'Active'],
                array_map(self::artist(...), self::of(ArtistCandidate::class, $candidates->candidates)),
            ],
        };
    }

    /** @return list<string|int> */
    private static function release(ReleaseCandidate $release): array
    {
        return [
            $release->id,
            $release->score,
            MusicBrainzMatchOutput::withDisambiguation($release->title, $release->disambiguation),
            $release->artistCredit,
            MusicBrainzMatchOutput::cell($release->date),
            MusicBrainzMatchOutput::cell($release->country),
            MusicBrainzMatchOutput::cell($release->labels),
            MusicBrainzMatchOutput::cell($release->formats),
            $release->trackCount,
            AdminCommandSupport::yesNo($release->hasFrontCover),
        ];
    }

    /** @return list<string|int> */
    private static function recording(RecordingCandidate $recording): array
    {
        return [
            $recording->id,
            $recording->score,
            MusicBrainzMatchOutput::withDisambiguation($recording->title, $recording->disambiguation),
            $recording->artistCredit,
            MusicBrainzMatchOutput::milliseconds($recording->lengthMs),
            self::releases($recording->releases),
        ];
    }

    /** @return list<string|int> */
    private static function artist(ArtistCandidate $artist): array
    {
        return [
            $artist->id,
            $artist->score,
            $artist->name,
            MusicBrainzMatchOutput::cell($artist->disambiguation),
            MusicBrainzMatchOutput::cell($artist->type),
            MusicBrainzMatchOutput::cell($artist->country),
            self::active($artist),
        ];
    }

    /**
     * The first releases' titles, then how many more there are.
     *
     * @param list<RecordingCandidateRelease> $releases
     */
    private static function releases(array $releases): string
    {
        if ($releases === []) {
            return '-';
        }

        $titles = array_slice($releases, 0, self::RELEASES_SHOWN) |> (static fn (array $shown): array => array_column($shown, 'title'));
        $more = count($releases) - count($titles);

        return implode('; ', $titles) . ($more > 0 ? sprintf(' (+%d more)', $more) : '');
    }

    /** When the artist was active, such as "1985-09-12 to now"; "-" when MusicBrainz knows nothing of it. */
    private static function active(ArtistCandidate $artist): string
    {
        if ($artist->lifeSpanBegin === null && $artist->lifeSpanEnd === null && !$artist->ended) {
            return '-';
        }

        return sprintf('%s to %s', $artist->lifeSpanBegin ?? '?', $artist->lifeSpanEnd ?? ($artist->ended ? '?' : 'now'));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param list<object>    $candidates
     *
     * @return list<T>
     */
    private static function of(string $class, array $candidates): array
    {
        return array_filter($candidates, static fn (object $candidate): bool => $candidate instanceof $class) |> array_values(...);
    }
}
