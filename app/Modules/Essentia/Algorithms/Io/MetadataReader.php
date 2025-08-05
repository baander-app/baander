<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * MetadataReader


Outputs:

   [string] title - the title of the track
   [string] artist - the artist of the track
   [string] album - the album on which this track appears
   [string] comment - the comment field stored in the tags
   [string] genre - the genre as stored in the tags
   [string] tracknumber - the track number
   [string] date - the date of publication
     [pool] tagPool - the pool with all tags that were found
  [integer] duration - the duration of the track, in seconds
  [integer] bitrate - the bitrate of the track [kb/s]
  [integer] sampleRate - the sample rate [Hz]
  [integer] channels - the number of channels


Parameters:

  failOnError:
    bool ∈ {true,false} (default = false)
    if true, the algorithm throws an exception when encountering an error (e.g.
    trying to open an unsupported file format), otherwise the algorithm leaves
    all fields blank

  filename:
    string
    the name of the file from which to read the tags

  filterMetadata:
    bool (default = false)
    if true, only add tags from filterMetadataTags to the pool

  filterMetadataTags:
    vector_string (default = [])
    the list of tags to whitelist (original taglib names)

  tagPoolName:
    string (default = "metadata.tags")
    common prefix for tag descriptor names to use in tagPool


Description:

  This algorithm loads the metadata tags from an audio file as well as outputs
  its audio properties. Supported audio file types are:
    - mp3
    - flac
    - ogg
  An exception is thrown if unsupported filetype is given or if the file does
  not exist.
  Please observe that the .wav format is not supported. Also note that this
  algorithm incorrectly calculates the number of channels for a file in mp3
  format only for versions less than 1.5 of taglib in Linux and less or equal
  to 1.5 in Mac OS X
  If using this algorithm on Windows, you must ensure that the filename is
  encoded as UTF-8.
  This algorithm also contains some heuristic to try to deal with encoding
  errors in the tags and tries to do the appropriate conversion if a problem
  was found (mostly twice latin1->utf8 conversion).
  
  MetadataReader reads all metadata tags found in audio and stores them in the
  pool tagPool. Standard metadata tags found in audio files include strings
  mentioned in [1,2]. Tag strings are case-sensitive and they are converted to
  lower-case when stored to the pool. It is possible to filter these tags by
  using 'filterMetadataTags' parameter. This parameter should specify a
  white-list of tag strings as they are found in the audio file (e.g.,
  "ARTIST").
  
  References:
    [1] https://taglib.github.io/api/classTagLib_1_1PropertyMap.html#details
  
    [2] https://picard.musicbrainz.org/docs/mappings/
 * 
 * Category: Io
 * Mode: standard
 */
class MetadataReader extends BaseAlgorithm
{
    protected string $algorithmName = 'MetadataReader';
    protected string $mode = 'standard';
    protected string $category = 'Io';

    public function compute($input): array
    {
        try {
            // Input validation based on algorithm type
            $this->validateAlgorithmInput($input);
            
            // Convert input to appropriate format
            $processedInput = $this->prepareInput($input);
            
            // Execute the algorithm
            $result = $this->executeAlgorithm($processedInput);
            
            return $this->processOutput($result);
            
        } catch (\Exception $e) {
            throw new AlgorithmException(
                "Failed to compute MetadataReader: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validateAlgorithmInput($input): void
    {
        // Category-specific input validation
        switch ($this->category) {
            case 'Spectral':
            case 'Temporal':
                $this->validateInput($input, 'array');
                break;
            case 'Io':
                if (!is_string($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('IO algorithms expect string path or AudioVector');
                }
                break;
            default:
                // Generic validation
                if (!is_array($input) && !($input instanceof AudioVector) && !is_numeric($input)) {
                    throw new AlgorithmException('Unsupported input type for algorithm');
                }
        }
    }

    private function prepareInput($input)
    {
        if ($input instanceof AudioVector) {
            return $input->toCArray($this->essentia->getFFI());
        }
        
        return $input;
    }

    private function executeAlgorithm($input)
    {
        // This would contain the actual FFI calls to Essentia
        // Implementation depends on the specific algorithm
        
        // Placeholder for algorithm execution
        return [];
    }

    private function processOutput($result): array
    {
        // Process and format the output from Essentia
        return is_array($result) ? $result : [$result];
    }
}