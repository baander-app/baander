<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\ArtistCreditsTrait;
use MusicBrainz\Supplement\Field\ArtistsTrait;
use MusicBrainz\Supplement\Field\CollectionsTrait;
use MusicBrainz\Supplement\Field\DiscIdsTrait;
use MusicBrainz\Supplement\Field\LabelsTrait;
use MusicBrainz\Supplement\Field\MediaTrait;
use MusicBrainz\Supplement\Field\RecordingsTrait;
use MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingLevelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkLevelRelationsTrait;
use MusicBrainz\Supplement\Field\ReleasesTrait;
use MusicBrainz\Supplement\Fields;

class ReleaseFields extends Fields
{
    use ArtistsTrait;
    use CollectionsTrait;
    use LabelsTrait;
    use RecordingsTrait;
    use ReleasesTrait;

    use MediaTrait;
    use ArtistCreditsTrait;
    use DiscIdsTrait;
    use AnnotationTrait;
    use AliasesTrait;

    // relations
    use AreaRelationsTrait;
    use ArtistRelationsTrait;
    use EventRelationsTrait;
    use LabelRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use SeriesRelationsTrait;
    use UrlRelationsTrait;

    use RecordingLevelRelationsTrait;
    use WorkLevelRelationsTrait;
}
