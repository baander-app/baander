<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HarmonicBpm


Inputs:

  [vector_real] bpms - list of bpm candidates


Outputs:

  [vector_real] harmonicBpms - a list of bpms which are harmonically related to the bpm parameter 


Parameters:

  bpm:
    real ∈ [1,inf) (default = 60)
    the bpm used to find its harmonics

  threshold:
    real ∈ [1,inf) (default = 20)
    bpm threshold below which greatest common divisors are discarded

  tolerance:
    real ∈ [0,inf) (default = 5)
    percentage tolerance to consider two bpms are equal or equal to a harmonic


Description:

  This algorithm extracts bpms that are harmonically related to the tempo given
  by the 'bpm' parameter.
  The algorithm assumes a certain bpm is harmonically related to parameter bpm,
  when the greatest common divisor between both bpms is greater than threshold.
  The 'tolerance' parameter is needed in order to consider if two bpms are
  related. For instance, 120, 122 and 236 may be related or not depending on
  how much tolerance is given
  
  References:
    [1] Greatest common divisor - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Greatest_common_divisor
 * 
 * Category: Rhythm
 * Mode: standard
 */
class HarmonicBpm extends BaseAlgorithm
{
    protected string $algorithmName = 'HarmonicBpm';
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
                "Failed to compute HarmonicBpm: " . $e->getMessage(),
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