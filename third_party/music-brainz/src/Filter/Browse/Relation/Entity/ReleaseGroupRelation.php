<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ReleaseTrait;

/**
 * A relation between a release group and another entity.
 */
class ReleaseGroupRelation extends AbstractRelation
{
    use ArtistTrait;
    use CollectionTrait;
    use ReleaseTrait;
}
