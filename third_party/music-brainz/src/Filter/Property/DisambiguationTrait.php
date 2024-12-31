<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Disambiguation;

trait DisambiguationTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the disambiguation comment.
     *
     * @return string
     */
    public static function disambiguation(): string
    {
        return 'comment';
    }

    /**
     * Adds the disambiguation comment.
     *
     * @param Disambiguation $disambiguation The disambiguation comment
     *
     * @return Phrase
     */
    public function addDisambiguationComment(Disambiguation $disambiguation): Phrase
    {
        return $this->addPhrase((string) $disambiguation, self::disambiguation());
    }
}
