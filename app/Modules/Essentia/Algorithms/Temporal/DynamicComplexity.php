<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * DynamicComplexity


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

  [real] dynamicComplexity - the dynamic complexity coefficient
  [real] loudness - an estimate of the loudness [dB]


Parameters:

  frameSize:
    real ∈ (0,inf) (default = 0.20000000298)
    the frame size [s]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes the dynamic complexity defined as the average
  absolute deviation from the global loudness level estimate on the dB scale.
  It is related to the dynamic range and to the amount of fluctuation in
  loudness present in a recording. Silence at the beginning and at the end of a
  track are ignored in the computation in order not to deteriorate the results.
  
  References:
    [1] S. Streich, Music complexity: a multi-faceted description of audio
    content, UPF, Barcelona, Spain, 2007.
 * 
 * Category: Temporal
 * Mode: standard
 */
class DynamicComplexity extends BaseAlgorithm
{
    protected string $algorithmName = 'DynamicComplexity';
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
                "Failed to compute DynamicComplexity: " . $e->getMessage(),
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