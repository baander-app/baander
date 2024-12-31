<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Browse;

use MusicBrainz\Supplement\Field\AnnotationTrait;
use MusicBrainz\Supplement\Field\TagsTrait;
use MusicBrainz\Supplement\Field\UserTagsTrait;
use MusicBrainz\Supplement\Fields;

class UrlFields extends Fields
{
    use AnnotationTrait;
    use TagsTrait;
    use UserTagsTrait;
}
