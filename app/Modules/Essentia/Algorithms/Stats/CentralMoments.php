<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * CentralMoments


Inputs:

  [vector_real] array - the input array


Outputs:

  [vector_real] centralMoments - the central moments of the input array


Parameters:

  mode:
    string ∈ {pdf,sample} (default = "pdf")
    compute central moments considering array values as a probability density
    function over array index or as sample points of a distribution

  range:
    real ∈ (0,inf) (default = 1)
    the range of the input array, used for normalizing the results in the 'pdf'
    mode


Description:

  This algorithm extracts the 0th, 1st, 2nd, 3rd and 4th central moments of an
  array. It returns a 5-tuple in which the index corresponds to the order of
  the moment.
  
  Central moments cannot be computed on arrays which size is less than 2, in
  which case an exception is thrown.
  
  Note: the 'mode' parameter defines whether to treat array values as a
  probability distribution function (pdf) or as sample points of a distribution
  (sample).
  
  References:
    [1] Sample Central Moment -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/SampleCentralMoment.html
  
    [2] Central Moment - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Central_moment
 * 
 * Category: Stats
 * Mode: standard
 */
class CentralMoments extends BaseAlgorithm
{
    protected string $algorithmName = 'CentralMoments';
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
                "Failed to compute CentralMoments: " . $e->getMessage(),
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