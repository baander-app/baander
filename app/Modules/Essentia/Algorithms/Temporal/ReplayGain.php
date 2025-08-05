<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ReplayGain


Inputs:

  [vector_real] signal - the input audio signal (must be longer than 0.05ms)


Outputs:

  [real] replayGain - the distance to the suitable average replay level (~-31dbB) defined by SMPTE [dB]


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the input audio signal [Hz]


Description:

  This algorithm computes the Replay Gain loudness value of an audio signal.
  The algorithm is described in detail in [1]. The value returned is the
  'standard' ReplayGain value, not the value with 6dB preamplification as
  computed by lame, mp3gain, vorbisgain, and all widely used ReplayGain
  programs.
  
  This algorithm is only defined for input signals which size is larger than
  0.05ms, otherwise an exception will be thrown.
  
  As a pre-processing step, the algorithm applies equal-loudness filtering to
  the input signal. This is always done in the standard mode, but it can be
  turned off in the streaming mode, which is useful in the case one already has
  an equal-loudness filtered signal.
  
  References:
    [1] ReplayGain 1.0 specification,
  https://wiki.hydrogenaud.io/index.php?title=ReplayGain_1.0_specification
 * 
 * Category: Temporal
 * Mode: standard
 */
class ReplayGain extends BaseAlgorithm
{
    protected string $algorithmName = 'ReplayGain';
    protected string $mode = 'standard';
    protected string $category = 'Temporal';

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
                "Failed to compute ReplayGain: " . $e->getMessage(),
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