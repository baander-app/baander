<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\DiscIdsTrait;
use MusicBrainz\Supplement\Field\MediaTrait;
use MusicBrainz\Supplement\Field\RatingsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Field\ReleasesTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserRatingsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class LabelFields extends Fields
{
    use ReleasesTrait;
    use DiscIdsTrait;
    use MediaTrait;
    use AliasesTrait;
    use TagsTrait;
    use UserTagsTrait;
    use RatingsTrait;
    use UserRatingsTrait;
    use ArtistRelationsTrait;
    use LabelRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use ReleaseGroupRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
    use AnnotationTrait;
}
