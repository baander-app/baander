<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Fields;

class SeriesFields extends Fields
{
    // relations
    use ArtistRelationsTrait;
    use EventRelationsTrait;
    use LabelRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use ReleaseGroupRelationsTrait;
    use SeriesRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
}
