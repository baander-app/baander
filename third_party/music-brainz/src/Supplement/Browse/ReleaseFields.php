<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\ArtistCreditsTrait;
use MusicBrainz\Supplement\Field\DiscIdsTrait;
use MusicBrainz\Supplement\Field\GenresTrait;
use MusicBrainz\Supplement\Field\IsrcsTrait;
use MusicBrainz\Supplement\Field\LabelsTrait;
use MusicBrainz\Supplement\Field\MediaTrait;
use MusicBrainz\Supplement\Field\RecordingsTrait;
use MusicBrainz\Supplement\Field\ReleaseGroupsTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserGenresTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class ReleaseFields extends Fields
{
    use AnnotationTrait;
    use ArtistCreditsTrait;
    use DiscIdsTrait;
    use GenresTrait;
    use IsrcsTrait;
    use LabelsTrait;
    use MediaTrait;
    use RecordingsTrait;
    use ReleaseGroupsTrait;
    use TagsTrait;
    use UserGenresTrait;
    use UserTagsTrait;
}
