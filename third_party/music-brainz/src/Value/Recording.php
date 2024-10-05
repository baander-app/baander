<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\RecordingRelationListTrait;
use MusicBrainz\Value;

/**
 * A recording
 * A recording is an entity in MusicBrainz which can be linked to tracks on releases. Each track must always be
 * associated with a single recording, but a recording can be linked to any number of tracks.
 * A recording represents distinct audio that has been used to produce at least one released track through copying or
 * mastering. A recording itself is never produced solely through copying or mastering.
 * Generally, the audio represented by a recording corresponds to the audio at a stage in the production process before
 * any final mastering but after any editing or mixing.
 *
 * @link https://musicbrainz.org/doc/Recording
 */
class Recording implements Value
{
    use Property\MBIDTrait;
    use Property\LengthTrait;
    use Property\TitleTrait;
    use Property\FirstReleaseDateTrait;
    use Property\AliasesTrait;
    use Property\IpisTrait;
    use Property\CountryTrait;
    use Property\LabelCodeTrait;
    use Property\LabelTypeTrait;
    use Property\AreaTrait;
    use Property\LifeSpanTrait;
    use Property\SortNameTrait;
    use Property\IsnisTrait;
    use Property\DisambiguationTrait;
    use Property\RatingTrait;
    use Property\ReleasesTrait;
    use Property\TagsTrait;
    use Property\ArtistCreditsTrait;
    use Property\VideoFlagTrait;
    use Property\ScoreTrait;
    use RecordingRelationListTrait;

    /**
     * Constructs a recording.
     *
     * @param array $recording Array of information about a label
     */
    public function __construct(array $recording = [])
    {
        $this->setMbidFromArray($recording);
        $this->setLengthFromArray($recording);
        $this->setTitleFromArray($recording);
        $this->setFirstReleaseDateFromArray($recording);
        $this->setAliasesFromArray($recording);
        $this->setIpisFromArray($recording);
        $this->setCountryFromArray($recording);
        $this->setLabelCodeFromArray($recording);
        $this->setLabelTypeFromArray($recording);
        $this->setAreaFromArray($recording);
        $this->setLifeSpanFromArray($recording);
        $this->setSortNameFromArray($recording);
        $this->setDisambiguationFromArray($recording);
        $this->setRatingFromArray($recording);
        $this->setReleasesFromArray($recording);
        $this->setTagsFromArray($recording);
        $this->setArtistCreditsFromArray($recording);
        $this->setVideoFlagFromArray($recording);
        $this->setScoreFromArray($recording);
        $this->setRelationsFromArray($recording);
    }

    /**
     * Returns the recording as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getTitle();
    }
}
