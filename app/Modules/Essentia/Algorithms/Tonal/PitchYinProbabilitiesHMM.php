<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchYinProbabilitiesHMM


Inputs:

  [vector_vector_real] pitchCandidates - the pitch candidates
  [vector_vector_real] probabilities - the pitch probabilities


Outputs:

  [vector_real] pitch - pitch frequencies in Hz


Parameters:

  minFrequency:
    real ∈ (0,inf) (default = 61.7350006104)
    minimum detected frequency

  numberBinsPerSemitone:
    integer ∈ (1,inf) (default = 5)
    number of bins per semitone

  selfTransition:
    real ∈ (0,1) (default = 0.990000009537)
    the self transition probabilities

  yinTrust:
    real ∈ (0,1) (default = 0.5)
    the yin trust parameter


Description:

  This algorithm estimates the smoothed fundamental frequency given the pitch
  candidates and probabilities using hidden Markov models. It is a part of the
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
class PitchYinProbabilitiesHMM extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchYinProbabilitiesHMM';
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
                "Failed to compute PitchYinProbabilitiesHMM: " . $e->getMessage(),
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