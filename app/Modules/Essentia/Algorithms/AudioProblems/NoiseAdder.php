<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NoiseAdder


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [vector_real] signal - the output signal with the added noise


Parameters:

  fixSeed:
    bool ∈ {true,false} (default = false)
    if true, 0 is used as the seed for generating random values

  level:
    integer ∈ (-inf,0] (default = -100)
    power level of the noise generator [dB]


Description:

  This algorithm adds noise to an input signal. The average energy of the noise
  in dB is defined by the level parameter, and is generated using the Mersenne
  Twister random number generator.
  
  References:
    [1] Mersenne Twister: A random number generator (since 1997/10),
    http://www.math.sci.hiroshima-u.ac.jp/~m-mat/MT/emt.html
  
    [2] Mersenne twister - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Mersenne_twister
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class NoiseAdder extends BaseAlgorithm
{
    protected string $algorithmName = 'NoiseAdder';
    protected string $mode = 'standard';
    protected string $category = 'AudioProblems';

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
                "Failed to compute NoiseAdder: " . $e->getMessage(),
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