<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;

/**
 * A relation between a series and another entity.
 */
class SeriesRelation extends AbstractRelation
{
    use CollectionTrait;
}
