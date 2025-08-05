<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Dissonance


Inputs:

  [vector_real] frequencies - the frequencies of the spectral peaks (must be sorted by frequency)
  [vector_real] magnitudes - the magnitudes of the spectral peaks (must be sorted by frequency


Outputs:

  [real] dissonance - the dissonance of the audio signal (0 meaning completely consonant, and 1 meaning completely dissonant)


Description:

  This algorithm computes the sensory dissonance of an audio signal given its
  spectral peaks. Sensory dissonance (to be distinguished from musical or
  theoretical dissonance) measures perceptual roughness of the sound and is
  based on the roughness of its spectral peaks. Given the spectral peaks, the
  algorithm estimates total dissonance by summing up the normalized dissonance
  values for each pair of peaks. These values are computed using dissonance
  curves, which define dissonace between two spectral peaks according to their
  frequency and amplitude relations. The dissonance curves are based on
  perceptual experiments conducted in [1].
  Exceptions are thrown when the size of the input vectors are not equal or if
  input frequencies are not ordered ascendantly
  References:
    [1] R. Plomp and W. J. M. Levelt, "Tonal Consonance and Critical
    Bandwidth," The Journal of the Acoustical Society of America, vol. 38,
    no. 4, pp. 548–560, 1965.
  
    [2] Critical Band - Handbook for Acoustic Ecology
    http://www.sfu.ca/sonic-studio/handbook/Critical_Band.html
  
    [3] Bark Scale -  Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Bark_scale
 * 
 * Category: Tonal
 * Mode: standard
 */
class Dissonance extends BaseAlgorithm
{
    protected string $algorithmName = 'Dissonance';
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
                "Failed to compute Dissonance: " . $e->getMessage(),
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