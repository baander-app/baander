<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BinaryOperatorStream


Inputs:

  [vector_real] array1 - the first operand input array
  [vector_real] array2 - the second operand input array


Outputs:

  [vector_real] array - the array containing the result of binary operation


Parameters:

  type:
    string ∈ {add,subtract,multiply,divide} (default = "add")
    the type of the binary operator to apply to the input arrays


Description:

  This algorithm performs basic arithmetical operations element by element
  given two arrays.
  Note:
    - using this algorithm in streaming mode can cause diamond shape graphs
  which have not been tested with the current scheduler. There is NO GUARANTEE
  of its correct work for diamond shape graphs.
    - for y=0, x/y is invalid
 * 
 * Category: Standard
 * Mode: standard
 */
class BinaryOperatorStream extends BaseAlgorithm
{
    protected string $algorithmName = 'BinaryOperatorStream';
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
                "Failed to compute BinaryOperatorStream: " . $e->getMessage(),
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