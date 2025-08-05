<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TonalExtractor


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

                [real] chords_changes_rate - See ChordsDescriptors algorithm documentation
         [vector_real] chords_histogram - See ChordsDescriptors algorithm documentation
              [string] chords_key - See ChordsDescriptors algorithm documentation
                [real] chords_number_rate - See ChordsDescriptors algorithm documentation
       [vector_string] chords_progression - See ChordsDetection algorithm documentation
              [string] chords_scale - See ChordsDetection algorithm documentation
         [vector_real] chords_strength - See ChordsDetection algorithm documentation
  [vector_vector_real] hpcp - See HPCP algorithm documentation
  [vector_vector_real] hpcp_highres - See HPCP algorithm documentation
              [string] key_key - See Key algorithm documentation
              [string] key_scale - See Key algorithm documentation
                [real] key_strength - See Key algorithm documentation


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 4096)
    the framesize for computing tonal features

  hopSize:
    integer ∈ (0,inf) (default = 2048)
    the hopsize for computing tonal features

  tuningFrequency:
    real ∈ (0,inf) (default = 440)
    the tuning frequency of the input signal


Description:

  This algorithm computes tonal features for an audio signal
 * 
 * Category: Extractor
 * Mode: standard
 */
class TonalExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'TonalExtractor';
    protected string $mode = 'standard';
    protected string $category = 'Extractor';

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
                "Failed to compute TonalExtractor: " . $e->getMessage(),
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