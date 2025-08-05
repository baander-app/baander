<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LoopBpmEstimator


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] bpm - the estimated bpm (will be 0 if unsure)


Parameters:

  confidenceThreshold:
    real ∈ [0,1] (default = 0.949999988079)
    confidence threshold below which bpm estimate will be considered unreliable


Description:

  This algorithm estimates the BPM of audio loops. It internally uses
  PercivalBpmEstimator algorithm to produce a BPM estimate and
  LoopBpmConfidence to asses the reliability of the estimate. If the provided
  estimate is below the given confidenceThreshold, the algorithm outputs a BPM
  0.0, otherwise it outputs the estimated BPM. For more details on the BPM
  estimation method and the confidence measure please check the used
  algorithms.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class LoopBpmEstimator extends BaseAlgorithm
{
    protected string $algorithmName = 'LoopBpmEstimator';
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
                "Failed to compute LoopBpmEstimator: " . $e->getMessage(),
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