<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchSalienceFunction


Inputs:

  [vector_real] frequencies - the frequencies of the spectral peaks [Hz]
  [vector_real] magnitudes - the magnitudes of the spectral peaks


Outputs:

  [vector_real] salienceFunction - array of the quantized pitch salience values


Parameters:

  binResolution:
    real ∈ (0,100] (default = 10)
    salience function bin resolution [cents]

  harmonicWeight:
    real ∈ [0,1] (default = 0.800000011921)
    harmonic weighting parameter (weight decay ratio between two consequent
    harmonics, =1 for no decay)

  magnitudeCompression:
    real ∈ (0,1] (default = 1)
    magnitude compression parameter (=0 for maximum compression, =1 for no
    compression)

  magnitudeThreshold:
    real ∈ [0,inf) (default = 40)
    peak magnitude threshold (maximum allowed difference from the highest peak
    in dBs)

  numberHarmonics:
    integer ∈ [1,inf) (default = 20)
    number of considered harmonics

  referenceFrequency:
    real ∈ (0,inf) (default = 55)
    the reference frequency for Hertz to cent convertion [Hz], corresponding to
    the 0th cent bin


Description:

  This algorithm computes the pitch salience function of a signal frame given
  its spectral peaks. The salience function covers a pitch range of nearly five
  octaves (i.e., 6000 cents), starting from the "referenceFrequency", and is
  quantized into cent bins according to the specified "binResolution". The
  salience of a given frequency is computed as the sum of the weighted energies
  found at integer multiples (harmonics) of that frequency. 
  
  This algorithm is intended to receive its "frequencies" and "magnitudes"
  inputs from the SpectralPeaks algorithm. The output is a vector of salience
  values computed for the cent bins. The 0th bin corresponds to the specified
  "referenceFrequency".
  
  If both input vectors are empty (i.e., no spectral peaks are provided), a
  zero salience function is returned. Input vectors must contain positive
  frequencies, must not contain negative magnitudes and these input vectors
  must be of the same size, otherwise an exception is thrown. It is highly
  recommended to avoid erroneous peak duplicates (peaks of the same frequency
  occurring more than once), but it is up to the user's own control and no
  exception will be thrown.
  
  References:
    [1] J. Salamon and E. Gómez, "Melody extraction from polyphonic music
    signals using pitch contour characteristics," IEEE Transactions on Audio,
    Speech, and Language Processing, vol. 20, no. 6, pp. 1759–1770, 2012.
 * 
 * Category: Tonal
 * Mode: standard
 */
class PitchSalienceFunction extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchSalienceFunction';
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
                "Failed to compute PitchSalienceFunction: " . $e->getMessage(),
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