<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BpmHistogramDescriptors


Inputs:

  [vector_real] bpmIntervals - the list of bpm intervals [s]


Outputs:

         [real] firstPeakBPM - value for the highest peak [bpm]
         [real] firstPeakWeight - weight of the highest peak
         [real] firstPeakSpread - spread of the highest peak
         [real] secondPeakBPM - value for the second highest peak [bpm]
         [real] secondPeakWeight - weight of the second highest peak
         [real] secondPeakSpread - spread of the second highest peak
  [vector_real] histogram - bpm histogram [bpm]


Description:

  This algorithm computes beats per minute histogram and its statistics for the
  highest and second highest peak.
  Note: histogram vector contains occurance frequency for each bpm value, 0-th
  element corresponds to 0 bpm value.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class BpmHistogramDescriptors extends BaseAlgorithm
{
    protected string $algorithmName = 'BpmHistogramDescriptors';
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
                "Failed to compute BpmHistogramDescriptors: " . $e->getMessage(),
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