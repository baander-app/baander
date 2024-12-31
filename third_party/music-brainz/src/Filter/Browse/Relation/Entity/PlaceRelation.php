<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;

/**
 * A relation between a place and another entity.
 */
class PlaceRelation extends AbstractRelation
{
    use AreaTrait;
    use CollectionTrait;
}
