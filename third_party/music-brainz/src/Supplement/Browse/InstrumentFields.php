<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AliasesTrait;
use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\GenresTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserGenresTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class InstrumentFields extends Fields
{
    // ratings and user-ratings are not supported (docs are not correct)
    use AliasesTrait;
    use AnnotationTrait;
    use GenresTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserTagsTrait;
}
