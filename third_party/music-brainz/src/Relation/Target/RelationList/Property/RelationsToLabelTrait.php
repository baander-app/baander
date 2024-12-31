<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationToLabel;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a label.
 */
trait RelationsToLabelTrait
{
    /**
     * A list of relations to a label
     *
     * @var RelationToLabel[]|RelationToLabelList
     */
    private RelationToLabelList $labelRelations;

    /**
     * Returns a list of relations to a label.
     *
     * @return RelationToLabel[]|RelationToLabelList
     */
    public function getLabelRelations(): RelationToLabelList
    {
        return $this->labelRelations;
    }

    /**
     * Sets a list of relations to a label by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLabelRelationsFromArray(array $input): void
    {
        $this->labelRelations = is_null($input = ArrayAccess::getArray($input, EntityType::LABEL))
            ? new RelationToLabelList()
            : new RelationToLabelList($input);
    }
}
