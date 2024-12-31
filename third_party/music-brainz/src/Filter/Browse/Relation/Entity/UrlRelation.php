<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\Entity;

use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\RelatedEntity\ResourceTrait;

/**
 * A relation between an URL and another entity.
 */
class UrlRelation extends AbstractRelation
{
    use ResourceTrait;
}
