<?php

namespace Baander\Common\Streaming;

class TranscodeOptions
{
    public function __construct(
        public string        $inputFilePath,
        public string        $outputDirectoryPath,
        public string        $segmentPrefix,
        public int           $segmentOffset, // start segment number
        public array         $segmentTimes,
        public ?VideoProfile $videoProfile = null,
        public ?AudioProfile $audioProfile = null,
        public array $abrProfiles = [],
        public bool $directPlay = false,
)
    {

    }
}
