<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\GenresTrait;
use MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\InstrumentRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserGenresTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class AreaFields extends Fields
{
    use AliasesTrait;
    use AnnotationTrait;
    use GenresTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserTagsTrait;
    use AreaRelationsTrait;
    use EventRelationsTrait;
    use InstrumentRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use SeriesRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
}
