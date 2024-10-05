<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\ReleaseType;

trait PrimaryTypeTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the primary release type.
     *
     * @return string
     */
    public static function primaryType(): string
    {
        return 'primarytype';
    }

    /**
     * Adds the primary release type of the release group.
     *
     * @param ReleaseType $primaryType The primary release type of the release group
     *
     * @return Term
     */
    public function addPrimaryType(ReleaseType $primaryType): Term
    {
        return $this->addTerm((string) $primaryType, self::primaryType());
    }
}
