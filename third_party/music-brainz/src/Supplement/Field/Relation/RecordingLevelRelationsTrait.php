<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait RecordingLevelRelationsTrait
{
    /**
     * True, if recording level relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $recordingLevelRelations = false;

    /**
     * Returns true, if recording level relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForRecordingLevelRelations(): bool
    {
        return $this->recordingLevelRelations;
    }

    /**
     * Sets whether recording level relations should be included.
     *
     * @param bool $recordingLevelRelations True, if recording level relations should be included, otherwise false
     *
     * @return self
     */
    public function includeRecordingLevelRelations(bool $recordingLevelRelations = true): self
    {
        $this->recordingLevelRelations = $recordingLevelRelations;

        return $this;
    }
}
