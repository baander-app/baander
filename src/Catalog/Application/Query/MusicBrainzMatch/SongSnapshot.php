<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\LocalSong;
use App\Catalog\Domain\Model\Song;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A song with its credits, as read once for scoring and comparing it. */
#[Exclude]
final readonly class SongSnapshot
{
    /** @param list<CatalogCredit> $credits every credit of the song, as MatchCatalogReadInterface::songCredits() orders them */
    public function __construct(
        public Song $song,
        public array $credits,
    ) {
    }

    /** The artist text the song shows: the name its first primary credit is credited under. */
    public function primaryArtistName(): ?string
    {
        return array_find($this->credits, static fn (CatalogCredit $credit): bool => $credit->isPrimary())?->creditedName;
    }

    /**
     * The song as matching scores it.
     *
     * @param string|null $title  the title to score against instead of the song's own
     * @param string|null $artist the artist to score against instead of the song's credited names
     */
    public function localSong(?string $title = null, ?string $artist = null): LocalSong
    {
        return new LocalSong(
            title: $title ?? $this->song->getTitle(),
            artistNames: $artist === null ? self::performerNames($this->credits) : [$artist],
            lengthSeconds: $this->song->getLength(),
        );
    }

    /**
     * @param list<CatalogCredit> $credits
     *
     * @return list<string> the names the primary and featured credits show, primary first, each once
     */
    public static function performerNames(array $credits): array
    {
        $performers = array_filter($credits, static fn (CatalogCredit $credit): bool => $credit->isPerformerCredit());
        usort($performers, static fn (CatalogCredit $one, CatalogCredit $other): int => $other->isPrimary() <=> $one->isPrimary());

        return array_column($performers, 'creditedName')
            |> array_unique(...)
            |> array_values(...);
    }
}
