<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\MachineLearning;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TensorflowInputMusiCNN


Inputs:

  [vector_real] frame - the audio frame


Outputs:

  [vector_real] bands - the log compressed mel bands


Description:

  This algorithm computes mel-bands specific to the input of MusiCNN-based
  models.
  
  References:
    [1] Pons, J., & Serra, X. (2019). musicnn: Pre-trained convolutional neural
  networks for music audio tagging. arXiv preprint arXiv:1909.06654.
  
    [2] Supported models at https://essentia.upf.edu/models/
 * 
 * Category: MachineLearning
 * Mode: standard
 */
class TensorflowInputMusiCNN extends BaseAlgorithm
{
    protected string $algorithmName = 'TensorflowInputMusiCNN';
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
                "Failed to compute TensorflowInputMusiCNN: " . $e->getMessage(),
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