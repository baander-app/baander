<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Synthesis;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SineSubtraction


Inputs:

  [vector_real] frame - the input audio frame to subtract from
  [vector_real] magnitudes - the magnitudes of the sinusoidal peaks
  [vector_real] frequencies - the frequencies of the sinusoidal peaks [Hz]
  [vector_real] phases - the phases of the sinusoidal peaks


Outputs:

  [vector_real] frame - the output audio frame


Parameters:

  fftSize:
    integer ∈ [1,inf) (default = 512)
    the size of the FFT internal process (full spectrum size) and output frame.
    Minimum twice the hopsize.

  hopSize:
    integer ∈ [1,inf) (default = 128)
    the hop size between frames

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm subtracts the sinusoids computed with the sine model analysis
  from an input audio signal. It ouputs an audio signal.
 * 
 * Category: Synthesis
 * Mode: standard
 */
class SineSubtraction extends BaseAlgorithm
{
    protected string $algorithmName = 'SineSubtraction';
    protected string $mode = 'standard';
    protected string $category = 'Synthesis';

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
                "Failed to compute SineSubtraction: " . $e->getMessage(),
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