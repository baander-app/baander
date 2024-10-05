<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;

/**
 * A relation between an event and another entity.
 */
class EventRelation extends AbstractRelation
{
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\AreaTrait;
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\PlaceTrait;
}
