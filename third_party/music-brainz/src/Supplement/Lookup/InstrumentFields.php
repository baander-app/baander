<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\InstrumentRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Fields;

class InstrumentFields extends Fields
{
    use AreaRelationsTrait;
    use ArtistRelationsTrait;
    use InstrumentRelationsTrait;
    use LabelRelationsTrait;
    use UrlRelationsTrait;
}
