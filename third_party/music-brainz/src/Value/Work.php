<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A work
 * In MusicBrainz terminology, a work is a distinct intellectual or artistic creation, which can be expressed in the
 * form of one or more audio recordings. While a work in MusicBrainz is usually musical in nature, it is not necessarily
 * so. For example, a work could be a novel, play, poem or essay, later recorded as an oratory or audiobook.
 *
 * @link https://musicbrainz.org/doc/Work
 */
class Work implements Value
{
    use Property\AttributesTrait;
    use Property\DisambiguationTrait;
    use Property\IswcsTrait;
    use Property\LanguagesTrait;
    use Property\MBIDTrait;
    use Property\TitleTrait;
    use Property\WorkTypeTrait;

    /**
     * Constructs a work.
     *
     * @param array $work Information about the track
     */
    public function __construct(array $work = [])
    {
        $this->setAttributesFromArray($work);
        $this->setDisambiguationFromArray($work);
        $this->setDisambiguationFromArray($work);
        $this->setIswcsFromArray($work);
        $this->setLanguagesFromArray($work);
        $this->setMbidFromArray($work);
        $this->setTitleFromArray($work);
        $this->setWorkTypeFromArray($work);
    }

    /**
     * Returns the title of the work as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getTitle();
    }
}
