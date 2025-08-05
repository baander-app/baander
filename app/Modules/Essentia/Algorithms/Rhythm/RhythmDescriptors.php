<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RhythmDescriptors


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

  [vector_real] beats_position - See RhythmExtractor2013 algorithm documentation
         [real] confidence - See RhythmExtractor2013 algorithm documentation
         [real] bpm - See RhythmExtractor2013 algorithm documentation
  [vector_real] bpm_estimates - See RhythmExtractor2013 algorithm documentation
  [vector_real] bpm_intervals - See RhythmExtractor2013 algorithm documentation
         [real] first_peak_bpm - See BpmHistogramDescriptors algorithm documentation
         [real] first_peak_spread - See BpmHistogramDescriptors algorithm documentation
         [real] first_peak_weight - See BpmHistogramDescriptors algorithm documentation
         [real] second_peak_bpm - See BpmHistogramDescriptors algorithm documentation
         [real] second_peak_spread - See BpmHistogramDescriptors algorithm documentation
         [real] second_peak_weight - See BpmHistogramDescriptors algorithm documentation
  [vector_real] histogram - bpm histogram [bpm]


Description:

  This algorithm computes rhythm features (bpm, beat positions, beat histogram
  peaks) for an audio signal. It combines RhythmExtractor2013 for beat tracking
  and BPM estimation with BpmHistogramDescriptors algorithms.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class RhythmDescriptors extends BaseAlgorithm
{
    protected string $algorithmName = 'RhythmDescriptors';
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
                "Failed to compute RhythmDescriptors: " . $e->getMessage(),
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