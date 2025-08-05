<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * StartStopSilence


Inputs:

  [vector_real] frame - the input audio frames


Outputs:

  [integer] startFrame - number of the first non-silent frame
  [integer] stopFrame - number of the last non-silent frame


Parameters:

  threshold:
    integer ∈ (-inf,0]) (default = -60)
    the threshold below which average energy is defined as silence [dB]


Description:

  This algorithm outputs the frame at which sound begins and the frame at which
  sound ends.
  
  Note: In standard mode the algorithm is to be run iteratively on a sequence
  of frames. The outputs are updated on each iteration, and the final result is
  produced at the end of the sequence.
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class StartStopSilence extends BaseAlgorithm
{
    protected string $algorithmName = 'StartStopSilence';
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
                "Failed to compute StartStopSilence: " . $e->getMessage(),
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