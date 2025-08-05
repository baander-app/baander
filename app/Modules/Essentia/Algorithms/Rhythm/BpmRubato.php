<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BpmRubato


Inputs:

  [vector_real] beats - list of detected beat ticks [s]


Outputs:

  [vector_real] rubatoStart - list of timestamps where the start of a rubato region was detected [s]
  [vector_real] rubatoStop - list of timestamps where the end of a rubato region was detected [s]
      [integer] rubatoNumber - number of detected rubato regions


Parameters:

  longRegionsPruningTime:
    real ∈ [0,inf) (default = 20)
    time for the longest constant tempo region inside a rubato region [s]

  shortRegionsMergingTime:
    real ∈ [0,inf) (default = 4)
    time for the shortest constant tempo region from one tempo region to
    another [s]

  tolerance:
    real ∈ [0,1] (default = 0.0799999982119)
    minimum tempo deviation to look for


Description:

  This algorithm extracts the locations of large tempo changes from a list of
  beat ticks.
  
  An exception is thrown if the input beats are not in ascending order and/or
  if the input beats contain duplicate values.
  
  Quality: experimental (non-reliable, poor accuracy).
  
  References:
    [1] Tempo Rubato - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Rubato
 * 
 * Category: Rhythm
 * Mode: standard
 */
class BpmRubato extends BaseAlgorithm
{
    protected string $algorithmName = 'BpmRubato';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';

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
                "Failed to compute BpmRubato: " . $e->getMessage(),
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