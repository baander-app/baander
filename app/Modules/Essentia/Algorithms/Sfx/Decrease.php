<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Decrease


Inputs:

  [vector_real] array - the input array


Outputs:

  [real] decrease - the decrease of the input array


Parameters:

  range:
    real ∈ (-inf,inf) (default = 1)
    the range of the input array, used for normalizing the results


Description:

  This algorithm computes the decrease of an array defined as the linear
  regression coefficient. The range parameter is used to normalize the result.
  For a spectral centroid, the range should be equal to Nyquist and for an
  audio centroid the range should be equal to (audiosize - 1) / samplerate.
  The size of the input array must be at least two elements for "decrease" to
  be computed, otherwise an exception is thrown.
  References:
    [1] Least Squares Fitting -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/LeastSquaresFitting.html
 * 
 * Category: Sfx
 * Mode: standard
 */
class Decrease extends BaseAlgorithm
{
    protected string $algorithmName = 'Decrease';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute Decrease: " . $e->getMessage(),
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