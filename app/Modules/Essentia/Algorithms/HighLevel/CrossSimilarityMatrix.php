<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * CrossSimilarityMatrix


Inputs:

  [vector_vector_real] queryFeature - input frame features of the query song (e.g., a chromagram)
  [vector_vector_real] referenceFeature - input frame features of the reference song (e.g., a chromagram)


Outputs:

  [vector_vector_real] csm - 2D cross-similarity matrix of two input frame sequences (query vs reference)


Parameters:

  binarize:
    bool ∈ {true,false} (default = false)
    whether to binarize the euclidean cross-similarity matrix

  binarizePercentile:
    real ∈ [0,1] (default = 0.0949999988079)
    maximum percent of distance values to consider as similar in each row and
    each column

  frameStackSize:
    integer ∈ [0,inf) (default = 1)
    number of input frames to stack together and treat as a feature vector for
    similarity computation. Choose 'frameStackSize=1' to use the original input
    frames without stacking

  frameStackStride:
    integer ∈ [1,inf) (default = 1)
    stride size to form a stack of frames (e.g., 'frameStackStride'=1 to use
    consecutive frames; 'frameStackStride'=2 for using every second frame)


Description:

  This algorithm computes a euclidean cross-similarity matrix of two sequences
  of frame features. Similarity values can be optionally binarized
  
  The default parameters for binarizing are optimized according to [1] for
  cover song identification using chroma features. 
  
  The input feature arrays are vectors of frames of features in the shape
  (n_frames, n_features), where 'n_frames' is the number frames, 'n_features'
  is the number of frame features.
  
  An exception is also thrown if either one of the input feature arrays are
  empty or if the output similarity matrix is empty.
  
  References:
  
  [1] Serra, J., Serra, X., & Andrzejak, R. G. (2009). Cross recurrence
  quantification for cover song identification. New Journal of Physics.
 * 
 * Category: HighLevel
 * Mode: standard
 */
class CrossSimilarityMatrix extends BaseAlgorithm
{
    protected string $algorithmName = 'CrossSimilarityMatrix';
    protected string $mode = 'standard';
    protected string $category = 'HighLevel';

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
                "Failed to compute CrossSimilarityMatrix: " . $e->getMessage(),
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