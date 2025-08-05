<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\MachineLearning;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PCA


Inputs:

  [pool] poolIn - the pool where to get the spectral contrast feature vectors


Outputs:

  [pool] poolOut - the pool where to store the transformed feature vectors


Parameters:

  dimensions:
    integer ∈ [0,inf) (default = 0)
    number of dimension to reduce the input to

  namespaceIn:
    string (default = "spectral contrast")
    will look for this namespace in poolIn

  namespaceOut:
    string (default = "spectral contrast pca")
    will save to this namespace in poolOut


Description:

  This algorithm applies Principal Component Analysis based on the covariance
  matrix of the signal.
  
  References:
    [1] Principal component analysis - Wikipedia, the free enciclopedia
    http://en.wikipedia.org/wiki/Principal_component_analysis
 * 
 * Category: MachineLearning
 * Mode: standard
 */
class PCA extends BaseAlgorithm
{
    protected string $algorithmName = 'PCA';
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
                "Failed to compute PCA: " . $e->getMessage(),
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