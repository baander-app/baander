<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HighResolutionFeatures


Inputs:

  [vector_real] hpcp - the HPCPs, preferably of size >= 120


Outputs:

  [real] equalTemperedDeviation - measure of the deviation of HPCP local maxima with respect to equal-tempered bins
  [real] nonTemperedEnergyRatio - ratio between the energy on non-tempered bins and the total energy
  [real] nonTemperedPeaksEnergyRatio - ratio between the energy on non-tempered peaks and the total energy


Parameters:

  maxPeaks:
    integer ∈ [1,inf) (default = 24)
    maximum number of HPCP peaks to consider when calculating outputs


Description:

  This algorithm computes high-resolution chroma features from an HPCP vector.
  The vector's size must be a multiple of 12 and it is recommended that it be
  larger than 120. In otherwords, the HPCP's resolution should be 10 Cents or
  more.
  The high-resolution features being computed are:
  
    - Equal-temperament deviation: a measure of the deviation of HPCP local
  maxima with respect to equal-tempered bins. This is done by:
      a) Computing local maxima of HPCP vector
      b) Computing the deviations from equal-tempered (abs) bins and their
  average
  
    - Non-tempered energy ratio: the ratio betwen the energy on non-tempered
  bins and the total energy, computed from the HPCP average
  
    - Non-tempered peak energy ratio: the ratio betwen the energy on non
  tempered peaks and the total energy, computed from the HPCP average
  
  HighFrequencyFeatures is intended to be used in conjunction with HPCP
  algorithm. Any input vector which size is not a positive multiple of 12, will
  raise an exception.
  
  References:
    [1] E. Gómez and P. Herrera, "Comparative Analysis of Music Recordings
    from Western and Non-Western traditions by Automatic Tonal Feature
    Extraction," Empirical Musicology Review, vol. 3, pp. 140–156, 2008.
  
    [2] https://en.wikipedia.org/wiki/Equal_temperament
 * 
 * Category: HighLevel
 * Mode: standard
 */
class HighResolutionFeatures extends BaseAlgorithm
{
    protected string $algorithmName = 'HighResolutionFeatures';
    protected string $mode = 'standard';
    protected string $category = 'HighLevel';

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
                "Failed to compute HighResolutionFeatures: " . $e->getMessage(),
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