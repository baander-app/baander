<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\AliasNameTrait;
use MusicBrainz\Filter\Property\ArtistIdTrait;
use MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\IsrcTrait;
use MusicBrainz\Filter\Property\LanguageTrait;
use MusicBrainz\Filter\Property\TagNameTrait;
use MusicBrainz\Filter\Property\WorkNameWithAccentsTrait;
use MusicBrainz\Filter\Property\WorkNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\WorkTypeTrait;

/**
 * A filter for searching works
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Work
 */
class WorkFilter extends AbstractFilter
{
    use AliasNameTrait;
    use ArtistIdTrait;
    use ArtistNameWithoutAccentsTrait;
    use DisambiguationTrait;
    use IsrcTrait;
    use LanguageTrait;
    use TagNameTrait;
    use WorkNameWithAccentsTrait;
    use WorkNameWithoutAccentsTrait;
    use WorkTypeTrait;
}
