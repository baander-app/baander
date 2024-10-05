<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\ReleaseGroupRelationListTrait;
use MusicBrainz\Value;

/**
 * A release group
 * A release group, just as the name suggests, is used to group several different releases into a single logical entity.
 * Every release belongs to one, and only one release group.
 * Both release groups and releases are "albums" in a general sense, but with an important difference: a release is
 * something you can buy as media such as a CD or a vinyl record, while a release group embraces the overall concept of
 * an album - it doesn't matter how many CDs or editions/versions it had.
 * When an artist says "We've released our new album", they're talking about a release group. When their publisher says
 * "This new album gets released next week in Japan and next month in Europe", they're referring to the different
 * releases that belong in the above mentioned release group.
 * MusicBrainz automatically considers every release in the database to be part of a release group, even if this group
 * only contains the one release. When a brand new release is added to the database, a new release group is
 * automatically added as well.
 *
 * @link https://musicbrainz.org/doc/Release_Group
 */
class ReleaseGroup implements Value
{
    use Property\TitleTrait;
    use Property\MBIDTrait;
    use Property\DisambiguationTrait;
    use Property\PrimaryReleaseTypeTrait;
    use Property\SecondaryReleaseTypesListTrait;
    use ReleaseGroupRelationListTrait;

    /**
     * Constructs a release group.
     *
     * @param array $releaseGroup Information about the release group
     */
    public function __construct(array $releaseGroup = [])
    {
        $this->setTitleFromArray($releaseGroup);
        $this->setMbidFromArray($releaseGroup);
        $this->setDisambiguationFromArray($releaseGroup);
        $this->setPrimaryReleaseTypeFromArray($releaseGroup);
        $this->setSecondaryReleaseTypesFromArray($releaseGroup);
        $this->setRelationsFromArray($releaseGroup);
    }

    /**
     * Returns the release group as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getTitle();
    }
}
