<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Fields;

class ArtistFields extends Fields
{
    use \MusicBrainz\Supplement\Field\AliasesTrait;
    use \MusicBrainz\Supplement\Field\AnnotationTrait;
    use \MusicBrainz\Supplement\Field\DiscIdsTrait;
    use \MusicBrainz\Supplement\Field\MediaTrait;
    use \MusicBrainz\Supplement\Field\RatingsTrait;
    use \MusicBrainz\Supplement\Field\RecordingsTrait;
    use \MusicBrainz\Supplement\Field\ReleaseGroupsTrait;
    use \MusicBrainz\Supplement\Field\ReleasesTrait;
    use \MusicBrainz\Supplement\Field\TagsTrait;
    use \MusicBrainz\Supplement\Field\UserRatingsTrait;
    use \MusicBrainz\Supplement\Field\UserTagsTrait;
    use \MusicBrainz\Supplement\Field\VariousArtistsTrait;
    use \MusicBrainz\Supplement\Field\WorksTrait;

    use \MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\InstrumentRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\PlaceRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
    use \MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
}
