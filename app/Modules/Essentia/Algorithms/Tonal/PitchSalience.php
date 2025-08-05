<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchSalience


Inputs:

  [vector_real] spectrum - the input audio spectrum


Outputs:

  [real] pitchSalience - the pitch salience (normalized from 0 to 1)


Parameters:

  highBoundary:
    real ∈ (0,inf) (default = 5000)
    until which frequency we are looking for the minimum (must be smaller than
    half sampleRate) [Hz]

  lowBoundary:
    real ∈ (0,inf) (default = 100)
    from which frequency we are looking for the maximum (must not be larger
    than highBoundary) [Hz]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes the pitch salience of a spectrum. The pitch salience
  is given by the ratio of the highest auto correlation value of the spectrum
  to the non-shifted auto correlation value. Pitch salience was designed as
  quick measure of tone sensation. Unpitched sounds (non-musical sound effects)
  and pure tones have an average pitch salience value close to 0 whereas sounds
  containing several harmonics in the spectrum tend to have a higher value.
  
  Note that this algorithm may give better results when used with low sampling
  rates (i.e. 8000) as the information in the bands musically meaningful will
  have more relevance.
  
  This algorithm uses AutoCorrelation on the input "spectrum" and thus inherits
  its input requirements and exceptions. An exception is thrown at
  configuration time if "lowBoundary" is larger than "highBoundary" and/or if
  "highBoundary" is not smaller than half "sampleRate". At computation time, an
  exception is thrown if the input spectrum is empty. Also note that feeding
  silence to this algorithm will return zero.
  
  Application: characterizing percussive sounds.
  
  References:
    [1] J. Ricard "Towards computational morphological description of sound.
    DEA pre-thesis research work, Universitat Pompeu Fabra, Barcelona, 2004.
 * 
 * Category: Tonal
 * Mode: standard
 */
class PitchSalience extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchSalience';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

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
                "Failed to compute PitchSalience: " . $e->getMessage(),
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