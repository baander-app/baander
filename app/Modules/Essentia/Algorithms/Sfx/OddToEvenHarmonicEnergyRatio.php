<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * OddToEvenHarmonicEnergyRatio


Inputs:

  [vector_real] frequencies - the frequencies of the harmonic peaks (at least two frequencies in frequency ascending order)
  [vector_real] magnitudes - the magnitudes of the harmonic peaks (at least two magnitudes in frequency ascending order)


Outputs:

  [real] oddToEvenHarmonicEnergyRatio - the ratio between the odd and even harmonic energies of the given harmonic peaks


Description:

  This algorithm computes the ratio between a signal's odd and even harmonic
  energy given the signal's harmonic peaks. The odd to even harmonic energy
  ratio is a measure allowing to distinguish odd-harmonic-energy predominant
  sounds (such as from a clarinet) from equally important even-harmonic-energy
  sounds (such as from a trumpet). The required harmonic frequencies and
  magnitudes can be computed by the HarmonicPeaks algorithm.
  In the case when the even energy is zero, which may happen when only even
  harmonics where found or when only one peak was found, the algorithm outputs
  the maximum real number possible. Therefore, this algorithm should be used in
  conjunction with the harmonic peaks algorithm.
  If no peaks are supplied, the algorithm outputs a value of one, assuming
  either the spectrum was flat or it was silent.
  
  An exception is thrown if the input frequency and magnitude vectors have
  different size. Finally, an exception is thrown if the frequency and
  magnitude vectors are not ordered by ascending frequency.
  
  References:
    [1] K. D. Martin and Y. E. Kim, "Musical instrument identification:
    A pattern-recognition approach," The Journal of the Acoustical Society of
    America, vol. 104, no. 3, pp. 1768–1768, 1998.
  
    [2] K. Ringgenberg et al., "Musical Instrument Recognition,"
    http://cnx.org/content/col10313/1.3/pdf
 * 
 * Category: Sfx
 * Mode: standard
 */
class OddToEvenHarmonicEnergyRatio extends BaseAlgorithm
{
    protected string $algorithmName = 'OddToEvenHarmonicEnergyRatio';
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
                "Failed to compute OddToEvenHarmonicEnergyRatio: " . $e->getMessage(),
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