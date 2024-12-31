<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\GenresTrait;
use MusicBrainz\Supplement\Field\RatingsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\InstrumentRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\PlaceRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserGenresTrait;
use MusicBrainz\Supplement\Field\UserRatingsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class ArtistFields extends Fields
{
    use AliasesTrait;
    use AnnotationTrait;
    use GenresTrait;
    use RatingsTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserRatingsTrait;
    use UserTagsTrait;
    use ArtistRelationsTrait;
    use EventRelationsTrait;
    use InstrumentRelationsTrait;
    use LabelRelationsTrait;
    use PlaceRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use ReleaseGroupRelationsTrait;
    use SeriesRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
}
