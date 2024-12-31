<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\SeriesRelationListTrait;
use MusicBrainz\Value;

/**
 * A series
 * A series is a sequence of separate release groups, releases, recordings, works or events with a common theme. The
 * theme is usually prominent in the branding of the entities in the series and the individual entities will often have
 * been given a number indicating the position in the series.
 *
 * @see https://wiki.musicbrainz.org/Series
 */
class Series implements Value
{
    use SeriesRelationListTrait;
    use Property\AliasesTrait;
    use Property\DisambiguationTrait;
    use Property\MBIDTrait;
    use Property\NameTrait;

    /**
     * Constructs a series.
     *
     * @param array $series Array of information about the series
     */
    public function __construct(array $series = [])
    {
        $this->setAliasesFromArray($series);
        $this->setDisambiguationFromArray($series);
        $this->setMbidFromArray($series);
        $this->setNameFromArray($series);
        $this->setRelationsFromArray($series);
    }

    /**
     * Returns the series as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getName();
    }
}
