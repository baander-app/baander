<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\LabelTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\RecordingTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseGroupTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\TrackArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\TrackTrait;

/**
 * A relation between a release and another entity.
 */
class ReleaseRelation extends AbstractRelation
{
    use AreaTrait;
    use ArtistTrait;
    use CollectionTrait;
    use LabelTrait;
    use TrackTrait;
    use TrackArtistTrait;
    use RecordingTrait;
    use ReleaseGroupTrait;
}
