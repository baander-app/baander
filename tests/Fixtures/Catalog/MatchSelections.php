<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredit;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;

/** What an admin sends to apply a comparison, built from the comparison as the dialog shows it. */
final class MatchSelections
{
    /**
     * Every row ticked by default, with the current value it shows, and every merge and removal as
     * listed. Fields named in $alsoTicked are ticked as well, on the item and on every paired song.
     *
     * @param list<MatchField> $alsoTicked
     */
    public static function defaults(MatchComparison $comparison, array $alsoTicked = []): MatchSelection
    {
        $ticked = static fn (MatchFieldRow $row): bool => $row->ticked || ($row->isSelectable() && in_array($row->field, $alsoTicked, true));
        $fields = array_map(
            static fn (MatchFieldRow $row): SeenField => new SeenField($row->field, $row->current),
            array_filter($comparison->fields, $ticked),
        ) |> array_values(...);
        $credits = $comparison->credits?->ticked === true ? [self::seen($comparison->credits, null)] : [];
        foreach ($comparison->tracks as $track) {
            if ($track->song === null) {
                continue;
            }
            foreach (array_filter($track->fields, $ticked) as $row) {
                $fields[] = new SeenField($row->field, $row->current, $track->song->publicId);
            }
            if ($track->credits?->ticked === true) {
                $credits[] = self::seen($track->credits, $track->credits->songPublicId);
            }
        }

        return new MatchSelection(
            fields: $fields,
            credits: $credits,
            artistActions: array_filter($comparison->artistActions, static fn (MatchArtistAction $action): bool => $action->ticked)
                |> (static fn (array $actions): array => array_column($actions, 'artistPublicId')),
            keptArtists: array_filter($comparison->artistActions, static fn (MatchArtistAction $action): bool => !$action->ticked)
                |> (static fn (array $actions): array => array_column($actions, 'artistPublicId')),
        );
    }

    /**
     * The selection as plain arrays, for a child process.
     *
     * @return array{fields: list<array{field: string, current: string|int|list<string>|null, song: string|null}>, credits: list<array{song: string|null, current: list<array{string, string|null, string}>}>, artistActions: list<string>, keptArtists: list<string>}
     */
    public static function toArray(MatchSelection $selection): array
    {
        return [
            'fields' => array_map(static fn (SeenField $field): array => ['field' => $field->field->value, 'current' => $field->current, 'song' => $field->songPublicId], $selection->fields),
            'credits' => array_map(
                static fn (SeenCredits $credits): array => [
                    'song' => $credits->songPublicId,
                    'current' => array_map(static fn (SeenCredit $credit): array => [$credit->artistPublicId, $credit->role, $credit->creditedName], $credits->current),
                ],
                $selection->credits,
            ),
            'artistActions' => $selection->artistActions,
            'keptArtists' => $selection->keptArtists,
        ];
    }

    /** @param array{fields: list<array{field: string, current: string|int|list<string>|null, song: string|null}>, credits: list<array{song: string|null, current: list<array{string, string|null, string}>}>, artistActions: list<string>, keptArtists: list<string>} $selection */
    public static function fromArray(array $selection): MatchSelection
    {
        return new MatchSelection(
            fields: array_map(static fn (array $field): SeenField => new SeenField(MatchField::from($field['field']), $field['current'], $field['song']), $selection['fields']),
            credits: array_map(
                static fn (array $credits): SeenCredits => new SeenCredits(
                    $credits['song'],
                    array_map(static fn (array $credit): SeenCredit => new SeenCredit(...$credit), $credits['current']),
                ),
                $selection['credits'],
            ),
            artistActions: $selection['artistActions'],
            keptArtists: $selection['keptArtists'],
        );
    }

    private static function seen(MatchCreditsRow $row, ?string $songPublicId): SeenCredits
    {
        return new SeenCredits($songPublicId, array_map(
            static fn (CatalogCredit $credit): SeenCredit => new SeenCredit($credit->artistPublicId, $credit->role, $credit->creditedName),
            $row->current,
        ));
    }
}
