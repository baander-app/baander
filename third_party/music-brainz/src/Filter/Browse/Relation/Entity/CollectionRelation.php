<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\EditorTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\EventTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\LabelTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\PlaceTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\RecordingTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseGroupTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\WorkTrait;

/**
 * A relation between a collection and another entity.
 */
class CollectionRelation extends AbstractRelation
{
    use AreaTrait;
    use ArtistTrait;
    use EditorTrait;
    use EventTrait;
    use LabelTrait;
    use PlaceTrait;
    use RecordingTrait;
    use ReleaseTrait;
    use ReleaseGroupTrait;
    use WorkTrait;
}
