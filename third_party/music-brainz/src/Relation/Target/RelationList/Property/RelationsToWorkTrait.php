<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Relation\Target\RelationToWork;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a work.
 */
trait RelationsToWorkTrait
{
    /**
     * A list of relations to a work
     *
     * @var RelationToWork[]|RelationToWorkList
     */
    private RelationToWorkList $workRelations;

    /**
     * Returns a list of relations to a work.
     *
     * @return RelationToWork[]|RelationToWorkList
     */
    public function getWorkRelations(): RelationToWorkList
    {
        return $this->workRelations;
    }

    /**
     * Sets a list of relations to a work by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setWorkRelationsFromArray(array $input): void
    {
        $this->workRelations = is_null($input = ArrayAccess::getArray($input, EntityType::WORK))
            ? new RelationToWorkList()
            : new RelationToWorkList($input);
    }
}
