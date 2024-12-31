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

class SeriesFields extends Fields
{
    use AliasesTrait;
    use AnnotationTrait;
    use GenresTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserTagsTrait;
}
