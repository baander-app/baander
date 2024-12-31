<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\RecordingTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseGroupTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\WorkTrait;

/**
 * A relation between an artist and another entity.
 */
class ArtistRelation extends AbstractRelation
{
    use AreaTrait;
    use CollectionTrait;
    use RecordingTrait;
    use ReleaseTrait;
    use ReleaseGroupTrait;
    use WorkTrait;
}
