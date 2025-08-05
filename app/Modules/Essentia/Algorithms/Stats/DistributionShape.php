<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * DistributionShape


Inputs:

  [vector_real] centralMoments - the central moments of a distribution


Outputs:

  [real] spread - the spread (variance) of the distribution
  [real] skewness - the skewness of the distribution
  [real] kurtosis - the kurtosis of the distribution


Description:

  This algorithm computes the spread (variance), skewness and kurtosis of an
  array given its central moments. The extracted features are good indicators
  of the shape of the distribution. For the required input see CentralMoments
  algorithm.
  The size of the input array must be at least 5. An exception will be thrown
  otherwise.
  
  References:
    [1] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004.
  
    [2] Variance - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Variance
  
    [3] Skewness - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Skewness
  
    [4] Kurtosis - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Kurtosis
 * 
 * Category: Stats
 * Mode: standard
 */
class DistributionShape extends BaseAlgorithm
{
    protected string $algorithmName = 'DistributionShape';
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
                "Failed to compute DistributionShape: " . $e->getMessage(),
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