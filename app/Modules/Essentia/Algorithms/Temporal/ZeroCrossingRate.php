<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ZeroCrossingRate


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] zeroCrossingRate - the zero-crossing rate


Parameters:

  threshold:
    real ∈ [0,inf] (default = 0)
    the threshold which will be taken as the zero axis in both positive and
    negative sign


Description:

  This algorithm computes the zero-crossing rate of an audio signal. It is the
  number of sign changes between consecutive signal values divided by the total
  number of values. Noisy signals tend to have higher zero-crossing rate.
  In order to avoid small variations around zero caused by noise, a threshold
  around zero is given to consider a valid zerocrosing whenever the boundary is
  crossed.
  
  Empty input signals will raise an exception.
  
  References:
    [1] Zero Crossing - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Zero-crossing_rate
  
    [2] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004
 * 
 * Category: Temporal
 * Mode: standard
 */
class ZeroCrossingRate extends BaseAlgorithm
{
    protected string $algorithmName = 'ZeroCrossingRate';
    protected string $mode = 'standard';
    protected string $category = 'Temporal';

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
                "Failed to compute ZeroCrossingRate: " . $e->getMessage(),
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