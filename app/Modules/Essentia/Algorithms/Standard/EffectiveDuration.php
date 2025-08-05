<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * EffectiveDuration


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] effectiveDuration - the effective duration of the signal [s]


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  thresholdRatio:
    real ∈ [0,1] (default = 0.40000000596)
    the ratio of the envelope maximum to be used as the threshold


Description:

  This algorithm computes the effective duration of an envelope signal. The
  effective duration is a measure of the time the signal is perceptually
  meaningful. This is approximated by the time the envelope is above or equal
  to a given threshold and is above the -90db noise floor. This measure allows
  to distinguish percussive sounds from sustained sounds but depends on the
  signal length.
  By default, this algorithm uses 40% of the envelope maximum as the threshold
  which is suited for short sounds. Note, that the 0% thresold corresponds to
  the duration of signal above -90db noise floor, while the 100% thresold
  corresponds to the number of times the envelope takes its maximum value.
  References:
    [1] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004
 * 
 * Category: Standard
 * Mode: standard
 */
class EffectiveDuration extends BaseAlgorithm
{
    protected string $algorithmName = 'EffectiveDuration';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

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
                "Failed to compute EffectiveDuration: " . $e->getMessage(),
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