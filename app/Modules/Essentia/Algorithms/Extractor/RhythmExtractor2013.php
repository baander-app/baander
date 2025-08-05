<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RhythmExtractor2013


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

         [real] bpm - the tempo estimation [bpm]
  [vector_real] ticks -  the estimated tick locations [s]
         [real] confidence - confidence with which the ticks are detected (ignore this value if using 'degara' method)
  [vector_real] estimates - the list of bpm estimates characterizing the bpm distribution for the signal [bpm]
  [vector_real] bpmIntervals - list of beats interval [s]


Parameters:

  maxTempo:
    integer ∈ [60,250] (default = 208)
    the fastest tempo to detect [bpm]

  method:
    string ∈ {multifeature,degara} (default = "multifeature")
    the method used for beat tracking

  minTempo:
    integer ∈ [40,180] (default = 40)
    the slowest tempo to detect [bpm]


Description:

  This algorithm extracts the beat positions and estimates their confidence as
  well as tempo in bpm for an audio signal. The beat locations can be computed
  using:
    - 'multifeature', the BeatTrackerMultiFeature algorithm
    - 'degara', the BeatTrackerDegara algorithm (note that there is no
  confidence estimation for this method, the output confidence value is always
  0)
  
  See BeatTrackerMultiFeature and BeatTrackerDegara algorithms for more
  details.
  
  Note that the algorithm requires the sample rate of the input signal to be
  44100 Hz in order to work correctly.
 * 
 * Category: Extractor
 * Mode: standard
 */
class RhythmExtractor2013 extends BaseAlgorithm
{
    protected string $algorithmName = 'RhythmExtractor2013';
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
                "Failed to compute RhythmExtractor2013: " . $e->getMessage(),
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