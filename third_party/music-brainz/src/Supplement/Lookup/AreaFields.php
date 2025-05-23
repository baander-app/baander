<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\Relation\AreaRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\EventRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\InstrumentRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Fields;

class AreaFields extends Fields
{
    use AreaRelationsTrait;
    use EventRelationsTrait;
    use InstrumentRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
}
