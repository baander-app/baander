<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Loudness


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] loudness - the loudness of the input signal


Description:

  This algorithm computes the loudness of an audio signal defined by Steven's
  power law. It computes loudness as the energy of the signal raised to the
  power of 0.67.
  
  References:
    [1] Energy (signal processing) - Wikipedia, the free encyclopedia
    http://en.wikipedia.org/wiki/Energy_%28signal_processing%29
  
    [2] Stevens' power law - Wikipedia, the free encyclopedia
    http://en.wikipedia.org/wiki/Stevens%27_power_law
  
    [3] S. S. Stevens, Psychophysics. Transaction Publishers, 1975.
 * 
 * Category: Temporal
 * Mode: standard
 */
class Loudness extends BaseAlgorithm
{
    protected string $algorithmName = 'Loudness';
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
                "Failed to compute Loudness: " . $e->getMessage(),
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