<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NoiseBurstDetector


Inputs:

  [vector_real] frame - the input frame (must be non-empty)


Outputs:

  [vector_real] indexes - indexes of the noisy samples


Parameters:

  alpha:
    real ∈ (0,1) (default = 0.899999976158)
    alpha coefficient for the Exponential Moving Average threshold estimation.

  silenceThreshold:
    integer ∈ (-inf,0) (default = -50)
    threshold to skip silent frames

  threshold:
    integer ∈ (-inf,inf) (default = 8)
    factor to control the dynamic theshold


Description:

  This algorithm detects noise bursts in the waveform by thresholding  the
  peaks of the second derivative. The threshold is computed using an
  Exponential Moving Average filter over the RMS of the second derivative of
  the input frame.
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class NoiseBurstDetector extends BaseAlgorithm
{
    protected string $algorithmName = 'NoiseBurstDetector';
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
                "Failed to compute NoiseBurstDetector: " . $e->getMessage(),
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