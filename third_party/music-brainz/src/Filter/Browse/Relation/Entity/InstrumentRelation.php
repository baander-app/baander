<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;

/**
 * A relation between an instrument and another entity.
 */
class InstrumentRelation extends AbstractRelation
{
    use CollectionTrait;
}
