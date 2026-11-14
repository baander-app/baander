<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Interface\Resource\MatchArtistActionResource;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Catalog\Interface\Resource\MatchCreditsRowResource;
use App\Catalog\Interface\Resource\MatchFieldRowResource;
use App\Catalog\Interface\Resource\MatchTrackResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a MusicBrainz match comparison as app:metadata:match:compare and app:metadata:match:apply
 * show it: one row per field and per credits row with the key an apply names it by, an album's
 * track pairs with their song IDs, and the artists the match would merge or remove with their
 * public IDs.
 *
 * @phpstan-import-type ComparisonData from MatchComparisonResource
 * @phpstan-import-type FieldRowData from MatchFieldRowResource
 * @phpstan-import-type CreditsRowData from MatchCreditsRowResource
 * @phpstan-import-type CurrentCreditData from MatchCreditsRowResource
 * @phpstan-import-type ProposedCreditData from MatchCreditsRowResource
 * @phpstan-import-type TrackData from MatchTrackResource
 * @phpstan-import-type ArtistActionData from MatchArtistActionResource
 */
final class MatchComparisonOutput
{
    public static function show(SymfonyStyle $io, MatchComparison $comparison): void
    {
        $data = MatchComparisonResource::from($comparison);
        $io->text(sprintf(
            '%s %s compared with MusicBrainz %s %s.',
            $comparison->type->label(),
            $comparison->publicId,
            $comparison->type->candidateNoun(),
            $comparison->candidateId,
        ));
        self::rows($io, $data);
        if ($data['tracks'] !== []) {
            $io->table(['Disc', '#', 'Release track', 'Length', 'Song ID', 'Song', 'Length'], array_map(self::track(...), $data['tracks']));
        }
        if ($data['artistActions'] !== []) {
            $io->table(['Artist ID', 'Artist', 'Action', 'Into', 'Tick', 'Needs ticked'], array_map(self::action(...), $data['artistActions']));
        }
        $io->text('Tick: yes is ticked by default, no is not, locked cannot be ticked, and same would change nothing.');
    }

    /**
     * Every row in the comparison's order: the item's fields and credits, then each paired song's.
     *
     * @param ComparisonData $data
     */
    private static function rows(SymfonyStyle $io, array $data): void
    {
        $rows = array_map(self::fieldRow(...), $data['fields']);
        if ($data['credits'] !== null) {
            $rows[] = self::creditsRow($data['credits']);
        }
        foreach ($data['tracks'] as $track) {
            $rows = [...$rows, ...array_map(self::fieldRow(...), $track['fields'])];
            if ($track['credits'] !== null) {
                $rows[] = self::creditsRow($track['credits']);
            }
        }

        if ($rows === []) {
            $io->text('MusicBrainz gives no value the match could set.');

            return;
        }

        $io->table(['Row', 'Current', 'MusicBrainz', 'Tick'], $rows);
    }

    /**
     * @param FieldRowData $row
     *
     * @return list<string>
     */
    private static function fieldRow(array $row): array
    {
        return [
            $row['key'],
            MusicBrainzMatchOutput::cell($row['current']),
            MusicBrainzMatchOutput::cell($row['proposed']),
            self::tick($row),
        ];
    }

    /**
     * @param CreditsRowData $row
     *
     * @return list<string>
     */
    private static function creditsRow(array $row): array
    {
        return [
            $row['key'],
            array_map(self::currentCredit(...), $row['current']) |> self::credits(...),
            array_map(self::proposedCredit(...), $row['proposed']) |> self::credits(...),
            self::tick($row),
        ];
    }

    /** @param CurrentCreditData $credit */
    private static function currentCredit(array $credit): string
    {
        return self::credited($credit['artistName'], $credit['creditedName'], [$credit['role'] ?? 'no role']);
    }

    /** @param ProposedCreditData $credit */
    private static function proposedCredit(array $credit): string
    {
        $notes = [$credit['role']];
        if ($credit['artist']['publicId'] === null) {
            $notes[] = 'new artist';
        }
        if ($credit['artist']['takesId']) {
            $notes[] = 'takes the ID';
        }

        return self::credited($credit['artist']['name'], $credit['creditedName'], $notes);
    }

    /**
     * The artist, the name it is credited under when that differs, and the notes, such as
     * `Post Malone as "Post Malone, Luke Combs" (primary, takes the ID)`.
     *
     * @param list<string> $notes
     */
    private static function credited(string $artist, string $creditedName, array $notes): string
    {
        $as = $creditedName === $artist ? '' : sprintf(' as "%s"', $creditedName);

        return sprintf('%s%s (%s)', $artist, $as, implode(', ', $notes));
    }

    /** @param list<string> $credits */
    private static function credits(array $credits): string
    {
        return $credits === [] ? '-' : implode('; ', $credits);
    }

    /** @param FieldRowData|CreditsRowData $row */
    private static function tick(array $row): string
    {
        return match (true) {
            $row['locked'] ?? false => 'locked',
            !$row['changes'] => 'same',
            default => AdminCommandSupport::yesNo($row['ticked']),
        };
    }

    /**
     * @param TrackData $track
     *
     * @return list<string>
     */
    private static function track(array $track): array
    {
        ['release' => $release, 'song' => $song] = $track;

        return [
            MusicBrainzMatchOutput::cell($release['disc'] ?? $song['disc'] ?? null),
            MusicBrainzMatchOutput::cell($release['number'] ?? $song['track'] ?? null),
            MusicBrainzMatchOutput::cell($release['title'] ?? null),
            MusicBrainzMatchOutput::milliseconds($release['lengthMs'] ?? null),
            MusicBrainzMatchOutput::cell($song['publicId'] ?? null),
            MusicBrainzMatchOutput::cell($song['title'] ?? null),
            MusicBrainzMatchOutput::seconds($song['lengthSeconds'] ?? null),
        ];
    }

    /**
     * @param ArtistActionData $action
     *
     * @return list<string>
     */
    private static function action(array $action): array
    {
        $target = $action['target'];

        return [
            $action['artistPublicId'],
            $action['artistName'],
            $action['kind'],
            match (true) {
                $target === null => '-',
                $target['publicId'] === null => sprintf('%s (new artist)', $target['name']),
                default => sprintf('%s (%s)', $target['name'], $target['publicId']),
            },
            AdminCommandSupport::yesNo($action['ticked']),
            MusicBrainzMatchOutput::cell($action['requiredRows']),
        ];
    }
}
