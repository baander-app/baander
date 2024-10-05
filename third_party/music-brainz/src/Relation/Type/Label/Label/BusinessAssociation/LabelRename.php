<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;

use MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;
use MusicBrainz\Value\Name;

/**
 * This describes a situation where a label has changed its name, either for purely aesthetic reasons or following a buyout/sellout/spin-off. Extra care should be taken with cases where complicated merge/split/restructure financial operations are done. For example, it's not a good idea to rename the label Verve into The Verve Music Group, as Verve continued its existence thereafter as an imprint.
 *
 * @link https://musicbrainz.org/relationship/e6159066-6013-4d09-a2f8-bc473f21e89e
 */
class LabelRename extends BusinessAssociation
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('label rename');
    }
}
