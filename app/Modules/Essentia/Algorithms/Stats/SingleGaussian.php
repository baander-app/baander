<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SingleGaussian


Inputs:

  [matrix_real] matrix - the input data matrix (e.g. the MFCC descriptor over frames)


Outputs:

  [vector_real] mean - the mean of the values
  [matrix_real] covariance - the covariance matrix
  [matrix_real] inverseCovariance - the inverse of the covariance matrix


Description:

  This algorithm estimates the single gaussian distribution for a matrix of
  feature vectors. For example, using the single gaussian on descriptors like
  MFCC with the symmetric Kullback-Leibler divergence might be a much better
  option than just the mean and variance of the descriptors over a whole
  signal.
  
  An exception is thrown if the covariance of the input matrix is singular or
  if the input matrix is empty.
  
  References:
    [1] E. Pampalk, "Computational models of music similarity and their
    application in music information retrieval,” Vienna University of
    Technology, 2006.
 * 
 * Category: Stats
 * Mode: standard
 */
class SingleGaussian extends BaseAlgorithm
{
    protected string $algorithmName = 'SingleGaussian';
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
                "Failed to compute SingleGaussian: " . $e->getMessage(),
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