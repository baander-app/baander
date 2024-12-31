<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\WorkTrait;

/**
 * A relation between a recording and another entity.
 */
class RecordingRelation extends AbstractRelation
{
    use ArtistTrait;
    use CollectionTrait;
    use ReleaseTrait;
    use WorkTrait;
}
