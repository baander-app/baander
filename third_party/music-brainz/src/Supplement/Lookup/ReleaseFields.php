<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Fields;

class ReleaseFields extends Fields
{
    use \MusicBrainz\Supplement\Field\ArtistsTrait;
    use \MusicBrainz\Supplement\Field\CollectionsTrait;
    use \MusicBrainz\Supplement\Field\LabelsTrait;
    use \MusicBrainz\Supplement\Field\RecordingsTrait;
    use \MusicBrainz\Supplement\Field\ReleasesTrait;

    use \MusicBrainz\Supplement\Field\MediaTrait;
    use \MusicBrainz\Supplement\Field\ArtistCreditsTrait;
    use \MusicBrainz\Supplement\Field\DiscIdsTrait;
    use \MusicBrainz\Supplement\Field\AnnotationTrait;
    use \MusicBrainz\Supplement\Field\AliasesTrait;
    // relations
    use \MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;

    use \MusicBrainz\Supplement\Field\Relation\RecordingLevelRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\WorkLevelRelationsTrait;
}
