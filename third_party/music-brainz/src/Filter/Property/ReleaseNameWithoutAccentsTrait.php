<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait ReleaseNameWithoutAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the label name (without accents).
     *
     * @return string
     */
    public static function releaseNameWithoutAccents(): string
    {
        return 'release';
    }

    /**
     * Adds the name of a release (without accents).
     *
     * @param Name $releaseNameWithoutAccents The name of a release
     *
     * @return Phrase
     */
    public function addReleaseName(Name $releaseNameWithoutAccents): Phrase
    {
        return $this->addPhrase((string) $releaseNameWithoutAccents, self::releaseNameWithoutAccents());
    }
}
