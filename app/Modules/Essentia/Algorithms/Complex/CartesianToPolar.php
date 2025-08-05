<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Complex;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * CartesianToPolar


Inputs:

  [vector_complex] complex - the complex input vector


Outputs:

  [vector_real] magnitude - the magnitude vector
  [vector_real] phase - the phase vector


Description:

  This algorithm converts an array of complex numbers from cartesian to polar
  form. It uses the Euler formula:
    z = x + i*y = |z|(cos(α) + i sin(α))
      where x = Real part, y = Imaginary part,
      and |z| = modulus = magnitude, α = phase in (-pi,pi]
  
  It returns the magnitude and the phase as 2 separate vectors.
  
  References:
    [1] Polar Coordinates -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/PolarCoordinates.html
  
    [2] Polar coordinate system - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Polar_coordinates
 * 
 * Category: Complex
 * Mode: standard
 */
class CartesianToPolar extends BaseAlgorithm
{
    protected string $algorithmName = 'CartesianToPolar';
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
                "Failed to compute CartesianToPolar: " . $e->getMessage(),
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