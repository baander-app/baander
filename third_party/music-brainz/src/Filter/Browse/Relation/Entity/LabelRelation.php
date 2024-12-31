<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseTrait;

/**
 * A relation between a label and another entity.
 */
class LabelRelation extends AbstractRelation
{
    use AreaTrait;
    use CollectionTrait;
    use ReleaseTrait;
}
