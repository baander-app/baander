<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching tags
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Tag
 */
class TagFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\TagNameTrait;
}
