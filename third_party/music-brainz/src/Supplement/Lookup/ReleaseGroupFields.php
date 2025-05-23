<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\ArtistCreditsTrait;
use MusicBrainz\Supplement\Field\ArtistsTrait;
use MusicBrainz\Supplement\Field\DiscIdsTrait;
use MusicBrainz\Supplement\Field\MediaTrait;
use MusicBrainz\Supplement\Field\RatingsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\SeriesRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\ReleasesTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserRatingsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class ReleaseGroupFields extends Fields
{
    use AliasesTrait;
    use ArtistsTrait;
    use ReleasesTrait;
    use DiscIdsTrait;
    use MediaTrait;
    use ArtistCreditsTrait;
    use TagsTrait;
    use UserTagsTrait;
    use RatingsTrait;
    use UserRatingsTrait;

    // relations
    use ArtistRelationsTrait;
    use EventRelationsTrait;
    use LabelRelationsTrait;
    use ReleaseGroupRelationsTrait;
    use SeriesRelationsTrait;
    use UrlRelationsTrait;
}
