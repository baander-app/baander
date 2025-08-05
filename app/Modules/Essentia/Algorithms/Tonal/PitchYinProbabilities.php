<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchYinProbabilities


Inputs:

  [vector_real] signal - the input signal frame


Outputs:

  [vector_real] pitch - the output pitch candidate frequencies in cents
  [vector_real] probabilities - the output pitch candidate probabilities
         [real] RMS - the output RMS value


Parameters:

  frameSize:
    integer ∈ [2,inf) (default = 2048)
    number of samples in the input frame

  lowAmp:
    real ∈ (0,1] (default = 0.10000000149)
    the low RMS amplitude threshold

  preciseTime:
    bool ∈ {true,false} (default = false)
    use non-standard precise YIN timing (slow).

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    sampling rate of the input audio [Hz]


Description:

  This algorithm estimates the fundamental frequencies, their probabilities
  given the frame of a monophonic music signal. It is a part of the
  implementation of the probabilistic Yin algorithm [1].
  
  An exception is thrown if an empty signal is provided.
  
  References:
    [1] M. Mauch and S. Dixon, "pYIN: A Fundamental Frequency Estimator
    Using Probabilistic Threshold Distributions," in Proceedings of the
    IEEE International Conference on Acoustics, Speech, and Signal Processing
    (ICASSP 2014)Project Report, 2004
 * 
 * Category: Tonal
 * Mode: standard
 */
class PitchYinProbabilities extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchYinProbabilities';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

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
                "Failed to compute PitchYinProbabilities: " . $e->getMessage(),
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