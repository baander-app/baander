<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\ArtistCreditsTrait;
use MusicBrainz\Supplement\Field\GenresTrait;
use MusicBrainz\Supplement\Field\IsrcsTrait;
use MusicBrainz\Supplement\Field\RatingsTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserGenresTrait;
use MusicBrainz\Supplement\Field\UserRatingsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class RecordingFields extends Fields
{
    use AnnotationTrait;
    use ArtistCreditsTrait;
    use IsrcsTrait;
    use GenresTrait;
    use RatingsTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserRatingsTrait;
    use UserTagsTrait;
}
