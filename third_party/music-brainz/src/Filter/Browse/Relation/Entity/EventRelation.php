<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\PlaceTrait;

/**
 * A relation between an event and another entity.
 */
class EventRelation extends AbstractRelation
{
    use AreaTrait;
    use ArtistTrait;
    use CollectionTrait;
    use PlaceTrait;
}
