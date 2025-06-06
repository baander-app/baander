<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\PlaceRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Fields;

class PlaceFields extends Fields
{
    use ArtistRelationsTrait;
    use PlaceRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
}
