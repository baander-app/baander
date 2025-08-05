<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LogAttackTime


Inputs:

  [vector_real] signal - the input signal envelope (must be non-empty)


Outputs:

  [real] logAttackTime - the log (base 10) of the attack time [log10(s)]
  [real] attackStart - the attack start time [s]
  [real] attackStop - the attack end time [s]


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]

  startAttackThreshold:
    real ∈ [0,1] (default = 0.20000000298)
    the percentage of the input signal envelope at which the starting point of
    the attack is considered

  stopAttackThreshold:
    real ∈ [0,1] (default = 0.899999976158)
    the percentage of the input signal envelope at which the ending point of
    the attack is considered


Description:

  This algorithm computes the log (base 10) of the attack time of a signal
  envelope. The attack time is defined as the time duration from when the sound
  becomes perceptually audible to when it reaches its maximum intensity. By
  default, the start of the attack is estimated as the point where the signal
  envelope reaches 20% of its maximum value in order to account for possible
  noise presence. Also by default, the end of the attack is estimated as as the
  point where the signal envelope has reached 90% of its maximum value, in
  order to account for the possibility that the max value occurres after the
  logAttack, as in trumpet sounds.
  
  With this said, LogAttackTime's input is intended to be fed by the output of
  the Envelope algorithm. In streaming mode, the RealAccumulator algorithm
  should be connected between Envelope and LogAttackTime.
  
  Note that startAttackThreshold cannot be greater than stopAttackThreshold and
  the input signal should not be empty. In any of these cases an exception will
  be thrown.
 * 
 * Category: Sfx
 * Mode: standard
 */
class LogAttackTime extends BaseAlgorithm
{
    protected string $algorithmName = 'LogAttackTime';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute LogAttackTime: " . $e->getMessage(),
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