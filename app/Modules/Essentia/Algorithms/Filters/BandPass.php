<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Filters;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BandPass


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

  [vector_real] signal - the filtered signal


Parameters:

  bandwidth:
    real ∈ (0,inf) (default = 500)
    the bandwidth of the filter [Hz]

  cutoffFrequency:
    real ∈ (0,inf) (default = 1500)
    the cutoff frequency for the filter [Hz]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm implements a 2nd order IIR band-pass filter. Because of its
  dependence on IIR, IIR's requirements are inherited.
  
  References:
    [1] U. Zölzer, DAFX - Digital Audio Effects, 2nd edition, p. 55,
    John Wiley & Sons, 2011
 * 
 * Category: Filters
 * Mode: standard
 */
class BandPass extends BaseAlgorithm
{
    protected string $algorithmName = 'BandPass';
    protected string $mode = 'standard';
    protected string $category = 'Filters';

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
                "Failed to compute BandPass: " . $e->getMessage(),
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