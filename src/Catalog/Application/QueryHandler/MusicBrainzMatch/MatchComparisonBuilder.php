<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\AlbumSnapshot;
use App\Catalog\Application\Query\MusicBrainzMatch\ArtistActionKind;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\ComparedReleaseTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\ComparedSong;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Application\Query\MusicBrainzMatch\ProposedCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\SongSnapshot;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalTrack;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Application\Service\MusicBrainzMatch\ReleaseTrack;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPair;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzLabel;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Shared\Domain\Model\Uuid;
use Closure;

/**
 * Works out what matching an album, song or artist with a MusicBrainz candidate would set, as a
 * comparison the admin ticks rows of. It reads the catalog and changes nothing; an apply builds the
 * same comparison again from the item and candidate it reads anew.
 *
 * - An album gets the release's values: its IDs, title, year (from the release date, else the
 *   release group's first release date), first label and catalogue number, barcode, country,
 *   disambiguation, genres (the release group's, else the release's own), front cover and artist
 *   credit. A candidate that stands for several twin editions offers only the values they all
 *   give. Each local song paired with a release track gets the track's recording ID (where the
 *   editions agree on it), title, number, disc, the album's year and the track's artist credit.
 * - A song gets the recording's ID, title and artist credit, and nothing of any album.
 * - An artist gets the candidate's ID, name, sort name, type, country, disambiguation and life span,
 *   unless another artist already holds the ID: then the match merges the artist into that holder.
 *
 * Credits follow MusicBrainz's order: the first artist primary, the rest featured. The primary
 * credit keeps the song's or album's own credited name; a featured credit takes the name MusicBrainz
 * credits. Each credited artist resolves as an import resolves it, so the comparison names the
 * local artist, or a new one, each credit would go to. An artist the ticked credit rows would leave
 * without any credit merges into the artist that takes over most of its primary credits (the first
 * of them on a tie), or is removed when it held none.
 */
final readonly class MatchComparisonBuilder
{
    public function __construct(
        private MatchCatalogReadInterface $catalog,
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * Credited artists resolve in the comparison's order: the album's credit, then each paired
     * track in release order. An apply resolves them in the same order.
     */
    public function album(AlbumSnapshot $snapshot, MatchCandidate $candidate): MatchComparison
    {
        $album = $snapshot->album;
        $release = $candidate->release;
        $locked = $album->getLockedFields();
        $year = self::agreed($candidate, self::year(...));

        $fields = [
            self::row(MatchField::ReleaseMbid, $album->getReleaseMbid(), $release->id, $locked),
            self::row(MatchField::Mbid, $album->getMbid(), self::agreed($candidate, static fn (MusicBrainzRelease $edition): ?string => $edition->releaseGroup?->id), $locked),
            self::row(MatchField::Title, $album->getTitle(), self::agreed($candidate, static fn (MusicBrainzRelease $edition): string => $edition->title), $locked),
            self::row(MatchField::Year, $album->getYear(), $year, $locked),
            self::row(MatchField::Label, $album->getLabel(), self::agreed($candidate, self::label(...)), $locked),
            self::row(MatchField::CatalogNumber, $album->getCatalogNumber(), self::agreed($candidate, self::catalogNumber(...)), $locked),
            self::row(MatchField::Barcode, $album->getBarcode(), self::agreed($candidate, static fn (MusicBrainzRelease $edition): ?string => $edition->barcode), $locked),
            self::row(MatchField::Country, $album->getCountry(), self::agreed($candidate, static fn (MusicBrainzRelease $edition): ?string => $edition->country), $locked),
            self::row(MatchField::Disambiguation, $album->getDisambiguation(), self::agreed($candidate, static fn (MusicBrainzRelease $edition): ?string => $edition->disambiguation), $locked),
            self::row(MatchField::Genres, $snapshot->genres, self::agreed($candidate, self::genres(...)), $locked),
            self::row(MatchField::Cover, $album->getCoverImageId()?->toString(), $release->hasFrontCover ? $release->id : null, $locked),
        ] |> array_filter(...) |> array_values(...);

        $albumCredit = self::agreed($candidate, static fn (MusicBrainzRelease $edition): MusicBrainzArtistCredit => $edition->artistCredit);
        $plans = $albumCredit === null || $albumCredit->artists === [] ? [] : [self::plan(null, $snapshot->credits, $albumCredit)];
        foreach ($candidate->pairs->pairs as $pair) {
            $song = $snapshot->song($pair->local->id);
            if ($song !== null && $pair->release->track->artistCredit->artists !== []) {
                $plans[] = self::plan($song, $snapshot->creditsOf($song), $pair->release->track->artistCredit);
            }
        }
        $rows = $this->creditRows($plans);
        $albumRow = array_find($rows, static fn (MatchCreditsRow $row): bool => $row->isAlbumCredits());
        $songRows = array_filter($rows, static fn (MatchCreditsRow $row): bool => !$row->isAlbumCredits())
            |> (static fn (array $songRows): array => array_column($songRows, null, 'songPublicId'));

        return new MatchComparison(
            type: MatchItemType::Album,
            publicId: $album->getPublicId()->toString(),
            candidateId: $release->id,
            fields: $fields,
            credits: $albumRow,
            tracks: self::tracks($snapshot, $candidate, $year, $songRows),
            artistActions: $this->artistActions($plans, $rows, $albumRow === null ? [] : [$album->getId()]),
        );
    }

    /** A song match sets fields of the song only; it never moves the song or touches its album. */
    public function song(SongSnapshot $snapshot, MusicBrainzRecording $recording): MatchComparison
    {
        $song = $snapshot->song;
        $locked = $song->getLockedFields();
        $plans = $recording->artistCredit->artists === [] ? [] : [self::plan($song, $snapshot->credits, $recording->artistCredit)];
        $rows = $this->creditRows($plans);

        return new MatchComparison(
            type: MatchItemType::Song,
            publicId: $song->getPublicId()->toString(),
            candidateId: $recording->id,
            fields: [
                self::row(MatchField::Mbid, $song->getMbid(), $recording->id, $locked),
                self::row(MatchField::Title, $song->getTitle(), $recording->title, $locked),
            ] |> array_filter(...) |> array_values(...),
            credits: $rows[0] ?? null,
            artistActions: $this->artistActions($plans, $rows, []),
        );
    }

    public function artist(Artist $artist, MusicBrainzArtist $candidate): MatchComparison
    {
        $holder = MusicbrainzId::tryFromString($candidate->id) |> $this->artists->findByMbid(...);
        if ($holder !== null && !$holder->getId()->equals($artist->getId())) {
            return new MatchComparison(
                type: MatchItemType::Artist,
                publicId: $artist->getPublicId()->toString(),
                candidateId: $candidate->id,
                fields: [],
                credits: null,
                artistActions: [new MatchArtistAction(
                    kind: ArtistActionKind::Merge,
                    artistPublicId: $artist->getPublicId()->toString(),
                    artistName: $artist->getName(),
                    target: new PredictedArtist($holder->getId(), $holder->getPublicId()->toString(), $holder->getName()),
                )],
            );
        }

        $locked = $artist->getLockedFields();

        return new MatchComparison(
            type: MatchItemType::Artist,
            publicId: $artist->getPublicId()->toString(),
            candidateId: $candidate->id,
            fields: [
                self::row(MatchField::Mbid, $artist->getMbid(), $candidate->id, $locked),
                self::row(MatchField::Name, $artist->getName(), $candidate->name, $locked),
                self::row(MatchField::SortName, $artist->getSortName(), $candidate->sortName, $locked),
                self::row(MatchField::Type, $artist->getType(), $candidate->type, $locked),
                self::row(MatchField::Country, $artist->getCountry(), $candidate->country, $locked),
                self::row(MatchField::Disambiguation, $artist->getDisambiguation(), $candidate->disambiguation, $locked),
                self::row(MatchField::LifeSpanBegin, $artist->getLifeSpanBegin()?->format('Y-m-d'), self::fullDate($candidate->lifeSpanBegin), $locked),
                self::row(MatchField::LifeSpanEnd, $artist->getLifeSpanEnd()?->format('Y-m-d'), self::fullDate($candidate->lifeSpanEnd), $locked),
            ] |> array_filter(...) |> array_values(...),
            credits: null,
        );
    }

    /**
     * The release's tracks in release order, each with the song paired to it and that song's rows,
     * then the songs paired with no track.
     *
     * @param array<string, MatchCreditsRow> $songRows by song public ID
     *
     * @return list<MatchTrack>
     */
    private static function tracks(AlbumSnapshot $snapshot, MatchCandidate $candidate, ?int $year, array $songRows): array
    {
        $pairs = array_column(
            array_map(static fn (TrackPair $pair): array => [self::trackKey($pair->release), $pair], $candidate->pairs->pairs),
            1,
            0,
        );
        $releaseTracks = array_map(
            static fn (TrackPair|ReleaseTrack $entry): ReleaseTrack => $entry instanceof TrackPair ? $entry->release : $entry,
            [...$candidate->pairs->pairs, ...$candidate->pairs->unpairedRelease],
        );
        usort($releaseTracks, static fn (ReleaseTrack $one, ReleaseTrack $other): int => [$one->disc, $one->track->position] <=> [$other->disc, $other->track->position]);

        $track = static function (ReleaseTrack $releaseTrack) use ($snapshot, $candidate, $year, $songRows, $pairs): MatchTrack {
            $recordingId = $candidate->recordingId($releaseTrack);
            $compared = ComparedReleaseTrack::of($releaseTrack, $recordingId);
            $pair = $pairs[self::trackKey($releaseTrack)] ?? null;
            $song = $pair === null ? null : $snapshot->song($pair->local->id);
            if ($song === null) {
                return new MatchTrack(null, $compared);
            }

            return new MatchTrack(
                song: ComparedSong::of($song),
                release: $compared,
                fields: self::songRows($song, $releaseTrack, $recordingId, $year),
                credits: $songRows[$song->getPublicId()->toString()] ?? null,
            );
        };
        $unpairedSongs = array_map(
            static fn (LocalTrack $local): ?Song => $snapshot->song($local->id),
            $candidate->pairs->unpairedLocal,
        ) |> array_filter(...);

        return [
            ...array_map($track, $releaseTracks),
            ...array_map(static fn (Song $song): MatchTrack => new MatchTrack(ComparedSong::of($song), null), $unpairedSongs),
        ];
    }

    /** @return list<MatchFieldRow> */
    private static function songRows(Song $song, ReleaseTrack $track, ?string $recordingId, ?int $year): array
    {
        $locked = $song->getLockedFields();

        return [
            self::row(MatchField::Mbid, $song->getMbid(), $recordingId, $locked),
            self::row(MatchField::Title, $song->getTitle(), $track->track->title, $locked),
            self::row(MatchField::Track, $song->getTrack(), $track->track->position, $locked),
            self::row(MatchField::Disc, $song->getDisc(), $track->disc, $locked),
            self::row(MatchField::Year, $song->getYear(), $year, $locked),
        ] |> array_filter(...) |> array_values(...);
    }

    /**
     * What one credit row covers: the song (null for the album), every credit it holds now, and
     * the MusicBrainz credit it would take. A MusicBrainz credit that names no artist gets no row,
     * so a match never strips an item of its credits.
     *
     * @param list<CatalogCredit> $credits
     *
     * @return array{song: Song|null, credits: list<CatalogCredit>, credit: MusicBrainzArtistCredit}
     */
    private static function plan(?Song $song, array $credits, MusicBrainzArtistCredit $credit): array
    {
        return ['song' => $song, 'credits' => $credits, 'credit' => $credit];
    }

    /**
     * One credit row per plan, in order. The credited artists of every plan are resolved together,
     * in plan order, as one apply resolves them.
     *
     * @param list<array{song: Song|null, credits: list<CatalogCredit>, credit: MusicBrainzArtistCredit}> $plans
     *
     * @return list<MatchCreditsRow>
     */
    private function creditRows(array $plans): array
    {
        $predicted = array_map(static fn (array $plan): array => array_map(self::identity(...), $plan['credit']->artists), $plans)
            |> (static fn (array $identities): array => array_merge(...$identities))
            |> $this->catalog->predictArtists(...);

        return array_map(
            static fn (array $plan): MatchCreditsRow => MatchCreditsRow::compare(
                $plan['song']?->getPublicId()->toString(),
                array_filter($plan['credits'], static fn (CatalogCredit $credit): bool => $credit->isPerformerCredit()) |> array_values(...),
                self::proposed($plan['credit'], self::primaryCreditedName($plan['credits']), $predicted),
            ),
            $plans,
        );
    }

    /**
     * @param array<string, PredictedArtist> $predicted by identity key
     *
     * @return list<ProposedCredit>
     */
    private static function proposed(MusicBrainzArtistCredit $credit, ?string $primaryName, array $predicted): array
    {
        return array_map(
            static fn (MusicBrainzCreditedArtist $artist, int $index): ProposedCredit => new ProposedCredit(
                role: $index === 0 ? ArtistRole::Primary : ArtistRole::Featured,
                creditedName: $index === 0 ? $primaryName ?? $artist->name : $artist->name,
                musicbrainzArtistId: $artist->artistId,
                artist: $predicted[self::identity($artist)->key()] ?? PredictedArtist::created($artist->name),
            ),
            $credit->artists,
            array_keys($credit->artists),
        );
    }

    /**
     * The merges and removals the credit rows would cause. An artist qualifies when the changing
     * rows hold every credit it has: no row keeps it as a proposed artist, no unchanged row or
     * credit of another role keeps it, and it holds no credit on a song or album outside the rows.
     *
     * @param list<array{song: Song|null, credits: list<CatalogCredit>, credit: MusicBrainzArtistCredit}> $plans
     * @param list<MatchCreditsRow> $rows   one per plan
     * @param list<Uuid>            $albumIds the albums whose own credits the rows cover
     *
     * @return list<MatchArtistAction>
     */
    private function artistActions(array $plans, array $rows, array $albumIds): array
    {
        $changing = array_filter($rows, static fn (MatchCreditsRow $row): bool => $row->changes());
        $kept = self::keptArtistIds($plans, $rows, $changing);

        $losing = [];
        foreach ($changing as $row) {
            foreach ($row->current as $credit) {
                if (!in_array($credit->artistId->toString(), $kept, true)) {
                    $losing[$credit->artistId->toString()] ??= $credit;
                }
            }
        }
        if ($losing === []) {
            return [];
        }

        $songIds = array_column($plans, 'song')
            |> array_filter(...)
            |> (static fn (array $songs): array => array_map(static fn (Song $song): Uuid => $song->getId(), $songs))
            |> array_values(...);
        $elsewhere = $this->catalog->artistsCreditedElsewhere(array_column($losing, 'artistId'), $songIds, $albumIds);

        return array_filter($losing, static fn (CatalogCredit $credit): bool => !in_array($credit->artistId->toString(), $elsewhere, true))
            |> (static fn (array $artists): array => array_map(static fn (CatalogCredit $artist): MatchArtistAction => self::action($artist, $changing), $artists))
            |> array_values(...);
    }

    /**
     * The artists the comparison keeps credited whatever is ticked: every existing artist a row
     * proposes, every credit of an unchanged row, and every credit of another role than primary or
     * featured, which no match replaces.
     *
     * @param list<array{song: Song|null, credits: list<CatalogCredit>, credit: MusicBrainzArtistCredit}> $plans
     * @param list<MatchCreditsRow>      $rows     one per plan
     * @param array<int, MatchCreditsRow> $changing the rows that change, by plan index
     *
     * @return list<string> artist IDs
     */
    private static function keptArtistIds(array $plans, array $rows, array $changing): array
    {
        $kept = [];
        foreach ($rows as $index => $row) {
            foreach ($row->proposed as $credit) {
                $kept[] = $credit->artist->id?->toString();
            }
            foreach ($plans[$index]['credits'] as $credit) {
                if (!isset($changing[$index]) || !$credit->isPerformerCredit()) {
                    $kept[] = $credit->artistId->toString();
                }
            }
        }

        return array_filter($kept) |> array_values(...);
    }

    /** @param array<int, MatchCreditsRow> $changing */
    private static function action(CatalogCredit $artist, array $changing): MatchArtistAction
    {
        $holds = static fn (MatchCreditsRow $row, bool $primary): bool => array_any(
            $row->current,
            static fn (CatalogCredit $credit): bool => $credit->artistId->equals($artist->artistId) && (!$primary || $credit->isPrimary()),
        );
        $required = array_filter($changing, static fn (MatchCreditsRow $row): bool => $holds($row, false));
        $takers = array_filter($required, static fn (MatchCreditsRow $row): bool => $holds($row, true))
            |> (static fn (array $rows): array => array_map(static fn (MatchCreditsRow $row): ?ProposedCredit => array_first($row->proposed), $rows))
            |> array_filter(...)
            |> array_values(...);
        $target = self::mainTaker($takers);

        return new MatchArtistAction(
            kind: $target === null ? ArtistActionKind::Remove : ArtistActionKind::Merge,
            artistPublicId: $artist->artistPublicId,
            artistName: $artist->artistName,
            target: $target,
            requiredSongs: array_column($required, 'songPublicId') |> array_filter(...) |> array_values(...),
            requiresAlbumCredits: array_any($required, static fn (MatchCreditsRow $row): bool => $row->isAlbumCredits()),
        );
    }

    /**
     * The artist that takes over most of the primary credits, the first of them on a tie.
     *
     * @param list<ProposedCredit> $takers
     */
    private static function mainTaker(array $takers): ?PredictedArtist
    {
        $keys = array_map(static fn (ProposedCredit $credit): string => $credit->artist->id?->toString() ?? 'new ' . $credit->musicbrainzArtistId, $takers);
        $counts = array_count_values($keys);
        arsort($counts);
        $index = array_search(array_key_first($counts), $keys, true);

        return $index === false ? null : $takers[$index]->artist;
    }

    private static function trackKey(ReleaseTrack $track): string
    {
        return $track->disc . '-' . $track->track->position;
    }

    private static function identity(MusicBrainzCreditedArtist $artist): ArtistIdentity
    {
        return new ArtistIdentity($artist->name, [$artist->artistId]);
    }

    /** @param list<CatalogCredit> $credits */
    private static function primaryCreditedName(array $credits): ?string
    {
        return array_find($credits, static fn (CatalogCredit $credit): bool => $credit->isPrimary())?->creditedName;
    }

    /**
     * A row for a field MusicBrainz gives a value for; null when it gives none.
     *
     * @param string|int|list<string>|null $current
     * @param string|int|list<string>|null $proposed
     * @param array<string>                $lockedFields
     */
    private static function row(MatchField $field, string|int|array|null $current, string|int|array|null $proposed, array $lockedFields): ?MatchFieldRow
    {
        if ($proposed === null || $proposed === [] || (is_string($proposed) && trim($proposed) === '')) {
            return null;
        }

        return MatchFieldRow::compare($field, $current, $proposed, in_array($field->value, $lockedFields, true));
    }

    /**
     * The value every edition the candidate stands for gives; null when they disagree.
     *
     * @template T
     *
     * @param Closure(MusicBrainzRelease): T $value
     *
     * @return T|null
     */
    private static function agreed(MatchCandidate $candidate, Closure $value): mixed
    {
        $values = array_map($value, $candidate->editions);
        $distinct = array_map(serialize(...), $values) |> array_unique(...) |> count(...);

        return $distinct === 1 ? $values[0] : null;
    }

    private static function year(MusicBrainzRelease $release): ?int
    {
        $date = $release->date ?? $release->releaseGroup->firstReleaseDate ?? '';

        return preg_match('/^(\d{4})/', $date, $matches) === 1 ? (int) $matches[1] : null;
    }

    private static function label(MusicBrainzRelease $release): ?string
    {
        return array_find($release->labels, static fn (MusicBrainzLabel $label): bool => trim($label->name ?? '') !== '')?->name;
    }

    private static function catalogNumber(MusicBrainzRelease $release): ?string
    {
        return array_find($release->labels, static fn (MusicBrainzLabel $label): bool => trim($label->catalogNumber ?? '') !== '')?->catalogNumber;
    }

    /** @return list<string> */
    private static function genres(MusicBrainzRelease $release): array
    {
        return ($release->releaseGroup->genres ?? []) ?: $release->genres;
    }

    /** A MusicBrainz date such as "1985" or "1985-09" as the day it starts, "1985-01-01"; null when there is none. */
    private static function fullDate(?string $date): ?string
    {
        if ($date === null || preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $date, $parts) !== 1) {
            return null;
        }

        return sprintf('%s-%s-%s', $parts[1], ($parts[2] ?? '') ?: '01', ($parts[3] ?? '') ?: '01');
    }
}
