<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching annotations
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Annotation
 */
class AnnotationFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\AnnotationTextTrait;
    use \MusicBrainz\Filter\Property\EntityIdTrait;
    use \MusicBrainz\Filter\Property\EntityNameTrait;
    use \MusicBrainz\Filter\Property\EntityTypeTrait;
}
