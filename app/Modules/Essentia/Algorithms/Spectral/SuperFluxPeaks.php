<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SuperFluxPeaks


Inputs:

  [vector_real] novelty - the input onset detection function


Outputs:

  [vector_real] peaks - detected peaks' instants [s]


Parameters:

  combine:
    real ∈ (0,inf) (default = 30)
    time threshold for double onsets detections (ms)

  frameRate:
    real ∈ (0,inf) (default = 172)
    frameRate

  pre_avg:
    real ∈ (0,inf) (default = 100)
    look back duration for moving average filter [ms]

  pre_max:
    real ∈ (0,inf) (default = 30)
    look back duration for moving maximum filter [ms]

  ratioThreshold:
    real ∈ [0,inf) (default = 16)
    ratio threshold for peak picking with respect to
    novelty_signal/novelty_average rate, use 0 to disable it (for low-energy
    onsets)

  threshold:
    real ∈ [0,inf) (default = 0.0500000007451)
    threshold for peak peaking with respect to the difference between
    novelty_signal and average_signal (for onsets in ambient noise)


Description:

  This algorithm detects peaks of an onset detection function computed by the
  SuperFluxNovelty algorithm. See SuperFluxExtractor for more details.
 * 
 * Category: Spectral
 * Mode: standard
 */
class SuperFluxPeaks extends BaseAlgorithm
{
    protected string $algorithmName = 'SuperFluxPeaks';
    protected string $mode = 'standard';
    protected string $category = 'Spectral';

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
                "Failed to compute SuperFluxPeaks: " . $e->getMessage(),
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