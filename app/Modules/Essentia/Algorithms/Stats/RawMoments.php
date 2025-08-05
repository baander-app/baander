<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RawMoments


Inputs:

  [vector_real] array - the input array


Outputs:

  [vector_real] rawMoments - the (raw) moments of the input array


Parameters:

  range:
    real ∈ (0,inf) (default = 22050)
    the range of the input array, used for normalizing the results


Description:

  This algorithm computes the first 5 raw moments of an array. The output array
  is of size 6 because the zero-ith moment is used for padding so that the
  first moment corresponds to index 1.
  
  Note:
    This algorithm has a range parameter, which usually represents a frequency
  (results will range from 0 to range). For a spectral centroid, the range
  should be equal to samplerate / 2. For an audio centroid, the frequency range
  should be equal to (audio_size-1) / samplerate.
  
  An exception is thrown if the input array's size is smaller than 2.
  
  References:
    [1] Raw Moment -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/RawMoment.html
 * 
 * Category: Stats
 * Mode: standard
 */
class RawMoments extends BaseAlgorithm
{
    protected string $algorithmName = 'RawMoments';
    protected string $mode = 'standard';
    protected string $category = 'Stats';

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
                "Failed to compute RawMoments: " . $e->getMessage(),
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