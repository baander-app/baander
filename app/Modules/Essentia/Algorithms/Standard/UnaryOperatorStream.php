<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * UnaryOperatorStream


Inputs:

  [vector_real] array - the input array


Outputs:

  [vector_real] array - the input array transformed by unary operation


Parameters:

  scale:
    real ∈ (-inf,inf) (default = 1)
    multiply result by factor

  shift:
    real ∈ (-inf,inf) (default = 0)
    shift result by value (add value)

  type:
    string ∈ {identity,abs,log10,log,ln,lin2db,db2lin,sin,cos,sqrt,square} (default = "identity")
    the type of the unary operator to apply to input array


Description:

  This algorithm performs basic arithmetical operations element by element
  given an array.
  Note:
    - log and ln are equivalent to the natural logarithm
    - for log, ln, log10 and lin2db, x is clipped to 1e-30 for x<1e-30
    - for x<0, sqrt(x) is invalid
    - scale and shift parameters define linear transformation to be applied to
  the resulting elements
 * 
 * Category: Standard
 * Mode: standard
 */
class UnaryOperatorStream extends BaseAlgorithm
{
    protected string $algorithmName = 'UnaryOperatorStream';
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
                "Failed to compute UnaryOperatorStream: " . $e->getMessage(),
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