<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Filters;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * DCRemoval


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

  [vector_real] signal - the filtered signal, with the DC component removed


Parameters:

  cutoffFrequency:
    real ∈ (0,inf) (default = 40)
    the cutoff frequency for the filter [Hz]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm removes the DC offset from a signal using a 1st order IIR
  highpass filter. Because of its dependence on IIR, IIR's requirements are
  inherited.
  
  References:
    [1] Smith, J.O.  Introduction to Digital Filters with Audio Applications,
    http://ccrma-www.stanford.edu/~jos/filters/DC_Blocker.html
 * 
 * Category: Filters
 * Mode: standard
 */
class DCRemoval extends BaseAlgorithm
{
    protected string $algorithmName = 'DCRemoval';
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
                "Failed to compute DCRemoval: " . $e->getMessage(),
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