<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationToUrl;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to an URL.
 */
trait RelationsToUrlTrait
{
    /**
     * A list of relations to an URL
     *
     * @var RelationToUrl[]|RelationToUrlList
     */
    private RelationToUrlList $urlRelations;

    /**
     * Returns a list of relations to an URL.
     *
     * @return RelationToUrl[]|RelationToUrlList
     */
    public function getUrlRelations(): RelationToUrlList
    {
        return $this->urlRelations;
    }

    /**
     * Sets a list of relations to an URL by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setUrlRelationsFromArray(array $input): void
    {
        $this->urlRelations = is_null($input = ArrayAccess::getArray($input, EntityType::URL))
            ? new RelationToUrlList()
            : new RelationToUrlList($input);
    }
}
