<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait RecordingRelationsTrait
{
    /**
     * True, if recording relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $recordingRelations = false;

    /**
     * Returns true, if recording relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForRecordingRelations(): bool
    {
        return $this->recordingRelations;
    }

    /**
     * Sets whether recording relations should be included.
     *
     * @param bool $recordingRelations True, if recording relations should be included, otherwise false
     *
     * @return self
     */
    public function includeRecordingRelations(bool $recordingRelations = true): self
    {
        $this->recordingRelations = $recordingRelations;

        return $this;
    }
}
