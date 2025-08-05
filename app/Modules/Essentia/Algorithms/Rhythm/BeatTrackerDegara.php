<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BeatTrackerDegara


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

  [vector_real] ticks -  the estimated tick locations [s]


Parameters:

  maxTempo:
    integer ∈ [60,250] (default = 208)
    the fastest tempo to detect [bpm]

  minTempo:
    integer ∈ [40,180] (default = 40)
    the slowest tempo to detect [bpm]


Description:

  This algorithm estimates the beat positions given an input signal. It
  computes 'complex spectral difference' onset detection function and utilizes
  the beat tracking algorithm (TempoTapDegara) to extract beats [1]. The
  algorithm works with the optimized settings of 2048/1024 frame/hop size for
  the computation of the detection function, with its posterior x2 resampling.)
  While it has a lower accuracy than BeatTrackerMultifeature (see the
  evaluation results in [2]), its computational speed is significantly higher,
  which makes reasonable to apply this algorithm for batch processings of large
  amounts of audio signals.
  
  Note that the algorithm requires the audio input with the 44100 Hz sampling
  rate in order to function correctly.
  
  References:
    [1] N. Degara, E. A. Rua, A. Pena, S. Torres-Guijarro, M. E. Davies, and
    M. D. Plumbley, "Reliability-informed beat tracking of musical signals,"
    IEEE Transactions on Audio, Speech, and Language Processing, vol. 20,
    no. 1, pp. 290–301, 2012.
  
    [2] J.R. Zapata, M.E.P. Davies and E. Gómez, "Multi-feature beat
  tracking,"
    IEEE Transactions on Audio, Speech, and Language Processing, vol. 22,
    no. 4, pp. 816-825, 2014.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class BeatTrackerDegara extends BaseAlgorithm
{
    protected string $algorithmName = 'BeatTrackerDegara';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';

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
                "Failed to compute BeatTrackerDegara: " . $e->getMessage(),
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