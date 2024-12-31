<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Lookup;

use MusicBrainz\Supplement\Field\ArtistCreditsTrait;
use MusicBrainz\Supplement\Field\ArtistsTrait;
use MusicBrainz\Supplement\Field\DiscIdsTrait;
use MusicBrainz\Supplement\Field\IsrcsTrait;
use MusicBrainz\Supplement\Field\LabelsTrait;
use MusicBrainz\Supplement\Field\MediaTrait;
use MusicBrainz\Supplement\Field\RecordingsTrait;
use MusicBrainz\Supplement\Field\Relation\ArtistRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\LabelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingLevelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\RecordingRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseGroupRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\ReleaseRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\UrlRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkLevelRelationsTrait;
use MusicBrainz\Supplement\Field\Relation\WorkRelationsTrait;
use MusicBrainz\Supplement\Field\ReleaseGroupsTrait;
use MusicBrainz\Supplement\Fields;

class DiscIdFields extends Fields
{
    use ArtistsTrait;
    use LabelsTrait;
    use RecordingsTrait;
    use ReleaseGroupsTrait;
    use MediaTrait;
    use ArtistCreditsTrait;
    use DiscIdsTrait;
    use IsrcsTrait;
    use ArtistRelationsTrait;
    use LabelRelationsTrait;
    use RecordingRelationsTrait;
    use ReleaseRelationsTrait;
    use ReleaseGroupRelationsTrait;
    use UrlRelationsTrait;
    use WorkRelationsTrait;
    use RecordingLevelRelationsTrait;
    use WorkLevelRelationsTrait;
}
