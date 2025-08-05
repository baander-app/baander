<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Centroid


Inputs:

  [vector_real] array - the input array


Outputs:

  [real] centroid - the centroid of the array


Parameters:

  range:
    real ∈ (0,inf) (default = 1)
    the range of the input array, used for normalizing the results


Description:

  This algorithm computes the centroid of an array. The centroid is normalized
  to a specified range. This algorithm can be used to compute spectral centroid
  or temporal centroid.
  
  The spectral centroid is a measure that indicates where the "center of mass"
  of the spectrum is. Perceptually, it has a robust connection with the
  impression of "brightness" of a sound, and therefore is used to characterise
  musical timbre. It is calculated as the weighted mean of the frequencies
  present in the signal, with their magnitudes as the weights.
  
  The temporal centroid is the point in time in a signal that is a temporal
  balancing point of the sound event energy. It can be computed from the
  envelope of the signal across audio samples [3] (see Envelope algorithm) or
  over the RMS level of signal across frames [4] (see RMS algorithm).
  
  Note:
  - For a spectral centroid [hz], frequency range should be equal to
  samplerate/2
  - For a temporal envelope centroid [s], range should be equal to
  (audio_size_in_samples-1) / samplerate
  - Exceptions are thrown when input array contains less than 2 elements.
  
  References:
    [1] Function Centroid -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/FunctionCentroid.html
    [2] Spectral centroid - Wikipedia, the free encyclopedia,
    https://en.wikipedia.org/wiki/Spectral_centroid
    [3] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004.
    [4] Klapuri, A., & Davy, M. (Eds.). (2007). Signal processing methods for
    music transcription. Springer Science & Business Media.
 * 
 * Category: Spectral
 * Mode: standard
 */
class Centroid extends BaseAlgorithm
{
    protected string $algorithmName = 'Centroid';
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
                "Failed to compute Centroid: " . $e->getMessage(),
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