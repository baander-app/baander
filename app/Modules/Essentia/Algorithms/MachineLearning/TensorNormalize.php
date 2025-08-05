<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\MachineLearning;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TensorNormalize


Inputs:

  [tensor_real] tensor - the input tensor


Outputs:

  [tensor_real] tensor - the normalized output tensor


Parameters:

  axis:
    integer ∈ [-1,4) (default = 0)
    Normalize along the given axis. -1 to normalize along all the dimensions

  scaler:
    string ∈ {standard,minMax} (default = "standard")
    the type of the normalization to apply to input tensor

  skipConstantSlices:
    bool ∈ {false,true} (default = true)
    Whether to prevent dividing by zero constant slices (zero standard
    deviation)


Description:

  This algorithm performs normalization over a tensor.
  When the axis parameter is set to -1 the input tensor is globally normalized.
  Any other value means that the tensor will be normalized along that axis.
  This algorithm supports Standard and MinMax normalizations.
  
  References:
    [1] Feature scaling - Wikipedia, the free encyclopedia,
    https://en.wikipedia.org/wiki/Feature_scaling
 * 
 * Category: MachineLearning
 * Mode: standard
 */
class TensorNormalize extends BaseAlgorithm
{
    protected string $algorithmName = 'TensorNormalize';
    protected string $mode = 'standard';
    protected string $category = 'MachineLearning';

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
                "Failed to compute TensorNormalize: " . $e->getMessage(),
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