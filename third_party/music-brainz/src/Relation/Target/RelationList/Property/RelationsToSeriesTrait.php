<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationToSeries;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a series.
 */
trait RelationsToSeriesTrait
{
    /**
     * A list of relations to a series
     *
     * @var RelationToSeries[]|RelationToSeriesList
     */
    private RelationToSeriesList $seriesRelations;

    /**
     * Returns a list of relations to a series.
     *
     * @return RelationToSeries[]|RelationToSeriesList
     */
    public function getSeriesRelations(): RelationToSeriesList
    {
        return $this->seriesRelations;
    }

    /**
     * Sets a list of relations to a series by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setSeriesRelationsFromArray(array $input): void
    {
        $this->seriesRelations = is_null($input = ArrayAccess::getArray($input, EntityType::SERIES))
            ? new RelationToSeriesList()
            : new RelationToSeriesList($input);
    }
}
