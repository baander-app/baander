<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Complex;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Magnitude


Inputs:

  [vector_complex] complex - the input vector of complex numbers


Outputs:

  [vector_real] magnitude - the magnitudes of the input vector


Description:

  This algorithm computes the absolute value of each element in a vector of
  complex numbers.
  
  References:
    [1] Complex Modulus -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/ComplexModulus.html
  
    [2] Complex number - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Complex_numbers#Absolute_value.2C_conjugation_a
  nd_distance.
 * 
 * Category: Complex
 * Mode: standard
 */
class Magnitude extends BaseAlgorithm
{
    protected string $algorithmName = 'Magnitude';
    protected string $mode = 'standard';
    protected string $category = 'Complex';

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
                "Failed to compute Magnitude: " . $e->getMessage(),
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