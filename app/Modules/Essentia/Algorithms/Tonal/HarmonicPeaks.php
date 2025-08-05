<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HarmonicPeaks


Inputs:

  [vector_real] frequencies - the frequencies of the spectral peaks [Hz] (ascending order)
  [vector_real] magnitudes - the magnitudes of the spectral peaks (ascending frequency order)
         [real] pitch - an estimate of the fundamental frequency of the signal [Hz]


Outputs:

  [vector_real] harmonicFrequencies - the frequencies of harmonic peaks [Hz]
  [vector_real] harmonicMagnitudes - the magnitudes of harmonic peaks


Parameters:

  maxHarmonics:
    integer ∈ [1,inf) (default = 20)
    the number of harmonics to return including F0

  tolerance:
    real ∈ (0,0.5) (default = 0.20000000298)
    the allowed ratio deviation from ideal harmonics


Description:

  This algorithm finds the harmonic peaks of a signal given its spectral peaks
  and its fundamental frequency.
  Note:
    - "tolerance" parameter defines the allowed fixed deviation from ideal
  harmonics, being a percentage over the F0. For example: if the F0 is 100Hz
  you may decide to allow a deviation of 20%, that is a fixed deviation of
  20Hz; for the harmonic series it is: [180-220], [280-320], [380-420], etc.
    - If "pitch" is zero, it means its value is unknown, or the sound is
  unpitched, and in that case the HarmonicPeaks algorithm returns an empty
  vector.
    - The output frequency and magnitude vectors are of size "maxHarmonics". If
  a particular harmonic was not found among spectral peaks, its ideal frequency
  value is output together with 0 magnitude.
  This algorithm is intended to receive its "frequencies" and "magnitudes"
  inputs from the SpectralPeaks algorithm.
    - When input vectors differ in size or are empty, an exception is thrown.
  Input vectors must be ordered by ascending frequency excluding DC components
  and not contain duplicates, otherwise an exception is thrown.
  
  References:
    [1] Harmonic Spectrum - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Harmonic_spectrum
 * 
 * Category: Tonal
 * Mode: standard
 */
class HarmonicPeaks extends BaseAlgorithm
{
    protected string $algorithmName = 'HarmonicPeaks';
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
                "Failed to compute HarmonicPeaks: " . $e->getMessage(),
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