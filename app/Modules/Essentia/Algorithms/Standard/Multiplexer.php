<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Multiplexer


Outputs:

  [vector_vector_real] data - the frame containing the input values and/or input frames


Parameters:

  numberRealInputs:
    integer ∈ [0,inf) (default = 0)
    the number of inputs of type Real to multiplex

  numberVectorRealInputs:
    integer ∈ [0,inf) (default = 0)
    the number of inputs of type vector<Real> to multiplex


Description:

  This algorithm returns a single vector from a given number of real values
  and/or frames. Frames from different inputs are multiplexed onto a single
  stream in an alternating fashion.
  
  This algorithm throws an exception if the number of input reals (or
  vector<real>) is less than the number specified in configuration parameters
  or if the user tries to acces an input which has not been specified.
  
  References:
    [1] Multiplexer - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Multiplexer
 * 
 * Category: Standard
 * Mode: standard
 */
class Multiplexer extends BaseAlgorithm
{
    protected string $algorithmName = 'Multiplexer';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

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
                "Failed to compute Multiplexer: " . $e->getMessage(),
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