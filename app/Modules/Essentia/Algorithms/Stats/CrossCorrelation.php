<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * CrossCorrelation


Inputs:

  [vector_real] arrayX - the first input array
  [vector_real] arrayY - the second input array


Outputs:

  [vector_real] crossCorrelation - the cross-correlation vector between the two input arrays (its size is equal to maxLag - minLag + 1)


Parameters:

  maxLag:
    integer ∈ (-inf,inf) (default = 1)
    the maximum lag to be computed between the two vectors

  minLag:
    integer ∈ (-inf,inf) (default = 0)
    the minimum lag to be computed between the two vectors


Description:

  This algorithm computes the cross-correlation vector of two signals. It
  accepts 2 parameters, minLag and maxLag which define the range of the
  computation of the innerproduct.
  
  An exception is thrown if "minLag" is larger than "maxLag". An exception is
  also thrown if the input vectors are empty.
  
  References:
    [1] Cross-correlation - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Cross-correlation
 * 
 * Category: Stats
 * Mode: standard
 */
class CrossCorrelation extends BaseAlgorithm
{
    protected string $algorithmName = 'CrossCorrelation';
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
                "Failed to compute CrossCorrelation: " . $e->getMessage(),
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