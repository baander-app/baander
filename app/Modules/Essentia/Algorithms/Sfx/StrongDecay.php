<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * StrongDecay


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

  [real] strongDecay - the strong decay


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes the Strong Decay of an audio signal. The Strong Decay
  is built from the non-linear combination of the signal energy and the signal
  temporal centroid, the latter being the balance of the absolute value of the
  signal. A signal containing a temporal centroid near its start boundary and a
  strong energy is said to have a strong decay.
  
  This algorithm returns 0.0 for zero signals (i.e. silence), and throws an
  exception when the signal's size is less than two as it can't compute its
  centroid.
  
  References:
    [1] F. Gouyon and P. Herrera, "Exploration of techniques for automatic
    labeling of audio drum tracks instruments," in MOSART: Workshop on Current
    Directions in Computer Music, 2001.
 * 
 * Category: Sfx
 * Mode: standard
 */
class StrongDecay extends BaseAlgorithm
{
    protected string $algorithmName = 'StrongDecay';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute StrongDecay: " . $e->getMessage(),
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