<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What an admin chose in a comparison: the rows ticked, each with the current value it showed, and
 * every artist merge or removal listed, ticked or not. An apply writes these rows only, and only
 * while each still shows what the admin saw.
 */
#[Exclude]
final readonly class MatchSelection
{
    /**
     * @param list<SeenField>   $fields        the ticked field rows: the item's own and, for an album, its songs'
     * @param list<SeenCredits> $credits       the ticked credits rows
     * @param list<string>      $artistActions the public IDs of the artists whose listed merge or removal stays ticked
     * @param list<string>      $keptArtists   the public IDs of the artists whose listed merge or removal the admin unticked
     */
    public function __construct(
        public array $fields = [],
        public array $credits = [],
        public array $artistActions = [],
        public array $keptArtists = [],
    ) {
    }
}
