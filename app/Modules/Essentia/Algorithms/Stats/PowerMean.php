<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PowerMean


Inputs:

  [vector_real] array - the input array (must contain only positive real numbers)


Outputs:

  [real] powerMean - the power mean of the input array


Parameters:

  power:
    real ∈ (-inf,inf) (default = 1)
    the power to which to elevate each element before taking the mean


Description:

  This algorithm computes the power mean of an array. It accepts one parameter,
  p, which is the power (or order or degree) of the Power Mean. Note that if
  p=-1, the Power Mean is equal to the Harmonic Mean, if p=0, the Power Mean is
  equal to the Geometric Mean, if p=1, the Power Mean is equal to the
  Arithmetic Mean, if p=2, the Power Mean is equal to the Root Mean Square.
  
  Exceptions are thrown if input array either is empty or it contains non
  positive numbers.
  
  References:
    [1] Power Mean -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/PowerMean.html
    [2] Generalized mean - Wikipedia, the free encyclopedia,
    https://en.wikipedia.org/wiki/Generalized_mean
 * 
 * Category: Stats
 * Mode: standard
 */
class PowerMean extends BaseAlgorithm
{
    protected string $algorithmName = 'PowerMean';
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
                "Failed to compute PowerMean: " . $e->getMessage(),
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