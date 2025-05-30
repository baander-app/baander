<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\ArtistsTrait;
use MusicBrainz\Supplement\Field\RatingsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Field\ReleaseGroupsTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserRatingsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class WorkFields extends Fields
{
    use ArtistsTrait;
    use AliasesTrait;
    use TagsTrait;
    use UserTagsTrait;
    use RatingsTrait;
    use UserRatingsTrait;
    use ArtistRelationsTrait;
    use LabelRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use ReleaseGroupsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
    use AnnotationTrait;
}
