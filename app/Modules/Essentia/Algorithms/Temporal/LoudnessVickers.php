<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LoudnessVickers


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] loudness - the Vickers loudness [dB]


Parameters:

  sampleRate:
    real ∈ [44100,44100] (default = 44100)
    the audio sampling rate of the input signal which is used to create the
    weight vector [Hz] (currently, this algorithm only works on signals with a
    sampling rate of 44100Hz)


Description:

  This algorithm computes Vickers's loudness of an audio signal. Currently,
  this algorithm only works for signals with a 44100Hz sampling rate. This
  algorithm is meant to be given frames of audio as input (not entire audio
  signals). The algorithm described in the paper performs a weighted average of
  the loudness value computed for each of the given frames, this step is left
  as a post processing step and is not performed by this algorithm.
  
  References:
    [1] E. Vickers, "Automatic Long-term Loudness and Dynamics Matching," in
    The 111th AES Convention, 2001.
 * 
 * Category: Temporal
 * Mode: standard
 */
class LoudnessVickers extends BaseAlgorithm
{
    protected string $algorithmName = 'LoudnessVickers';
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
                "Failed to compute LoudnessVickers: " . $e->getMessage(),
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