<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * MusicExtractor


Inputs:

  [string] filename - the input audiofile


Outputs:

  [pool] results - Analysis results pool with across-frames statistics
  [pool] resultsFrames - Analysis results pool with computed frame values


Parameters:

  analysisSampleRate:
    real ∈ (0,inf) (default = 44100)
    the analysis sampling rate of the audio signal [Hz]

  chromaprintCompute:
    bool ∈ {true,false} (default = false)
    compute the Chromaprint

  chromaprintDuration:
    real ∈ [0,inf) (default = 0)
    the amount of time from the beginning used to compute the Chromaprint. 0 to
    use the full audio length [s]

  endTime:
    real ∈ [0,inf) (default = 1000000)
    the end time of the slice you want to extract [s]

  gfccStats:
    vector_string (default = ["mean", "cov", "icov"])
    the statistics to compute for GFCC features

  highlevel:
    vector_string
    list of high-level classifier models (gaia2 history filenames) to apply
    using extracted features. Skip classification if not specified (empty list)

  loudnessFrameSize:
    integer ∈ (0,inf) (default = 88200)
    the frame size for computing average loudness

  loudnessHopSize:
    integer ∈ (0,inf) (default = 44100)
    the hop size for computing average loudness

  lowlevelFrameSize:
    integer ∈ (0,inf) (default = 2048)
    the frame size for computing low-level features

  lowlevelHopSize:
    integer ∈ (0,inf) (default = 1024)
    the hop size for computing low-level features

  lowlevelSilentFrames:
    string ∈ {drop,keep,noise} (default = "noise")
    whether to [keep/drop/add noise to] silent frames for computing low-level
    features

  lowlevelStats:
    vector_string (default = ["mean", "var", "stdev", "median", "min", "max", "dmean", "dmean2", "dvar", "dvar2"])
    the statistics to compute for low-level features

  lowlevelWindowType:
    string ∈ {hamming,hann,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "blackmanharris62")
    the window type for computing low-level features

  lowlevelZeroPadding:
    integer ∈ [0,inf) (default = 0)
    zero padding factor for computing low-level features

  mfccStats:
    vector_string (default = ["mean", "cov", "icov"])
    the statistics to compute for MFCC features

  profile:
    string
    profile filename. If specified, default parameter values are overwritten by
    values in the profile yaml file. If not specified (empty string), use
    values configured by user like in other normal algorithms

  requireMbid:
    bool ∈ {true,false} (default = false)
    ignore audio files without musicbrainz recording id tag (throw exception)

  rhythmMaxTempo:
    integer ∈ [60,250] (default = 208)
    the fastest tempo to detect [bpm]

  rhythmMethod:
    string ∈ {multifeature,degara} (default = "degara")
    the method used for beat tracking

  rhythmMinTempo:
    integer ∈ [40,180] (default = 40)
    the slowest tempo to detect [bpm]

  rhythmStats:
    vector_string (default = ["mean", "var", "stdev", "median", "min", "max", "dmean", "dmean2", "dvar", "dvar2"])
    the statistics to compute for rhythm features

  startTime:
    real ∈ [0,inf) (default = 0)
    the start time of the slice you want to extract [s]

  tonalFrameSize:
    integer ∈ (0,inf) (default = 4096)
    the frame size for computing tonal features

  tonalHopSize:
    integer ∈ (0,inf) (default = 2048)
    the hop size for computing tonal features

  tonalSilentFrames:
    string ∈ {drop,keep,noise} (default = "noise")
    whether to [keep/drop/add noise to] silent frames for computing tonal
    features

  tonalStats:
    vector_string (default = ["mean", "var", "stdev", "median", "min", "max", "dmean", "dmean2", "dvar", "dvar2"])
    the statistics to compute for tonal features

  tonalWindowType:
    string ∈ {hamming,hann,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "blackmanharris62")
    the window type for computing tonal features

  tonalZeroPadding:
    integer ∈ [0,inf) (default = 0)
    zero padding factor for computing tonal features


Description:

  This algorithm is a wrapper for Music Extractor. See documentation for
  'essentia_streaming_extractor_music'.
 * 
 * Category: Extractor
 * Mode: standard
 */
class MusicExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'MusicExtractor';
    protected string $mode = 'standard';
    protected string $category = 'Extractor';

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
                "Failed to compute MusicExtractor: " . $e->getMessage(),
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