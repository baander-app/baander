<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;

/**
 * A relation between a work and another entity.
 */
class WorkRelation extends AbstractRelation
{
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\ArtistTrait;
    use \MusicBrainz\Filter\Browse\Relation\RelatedEntity\CollectionTrait;
}
