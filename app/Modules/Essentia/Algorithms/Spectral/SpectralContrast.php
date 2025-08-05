<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SpectralContrast


Inputs:

  [vector_real] spectrum - the audio spectrum


Outputs:

  [vector_real] spectralContrast - the spectral contrast coefficients
  [vector_real] spectralValley - the magnitudes of the valleys


Parameters:

  frameSize:
    integer ∈ [2,inf) (default = 2048)
    the size of the fft frames

  highFrequencyBound:
    real ∈ (0,inf) (default = 11000)
    the upper bound of the highest band

  lowFrequencyBound:
    real ∈ (0,inf) (default = 20)
    the lower bound of the lowest band

  neighbourRatio:
    real ∈ (0,1] (default = 0.40000000596)
    the ratio of the bins in the sub band used to calculate the peak and valley

  numberBands:
    integer ∈ (0,inf) (default = 6)
    the number of bands in the filter

  sampleRate:
    real ∈ (0,inf) (default = 22050)
    the sampling rate of the audio signal

  staticDistribution:
    real ∈ [0,1] (default = 0.15000000596)
    the ratio of the bins to distribute equally


Description:

  This algorithm computes the Spectral Contrast feature of a spectrum. It is
  based on the Octave Based Spectral Contrast feature as described in [1]. The
  version implemented here is a modified version to improve discriminative
  power and robustness. The modifications are described in [2].
  
  References:
    [1] D.-N. Jiang, L. Lu, H.-J. Zhang, J.-H. Tao, and L.-H. Cai, "Music type
    classification by spectral contrast feature," in IEEE International
    Conference on Multimedia and Expo (ICME’02), 2002, vol. 1, pp. 113–116.
  
    [2] V. Akkermans, J. Serrà, and P. Herrera, "Shape-based spectral contrast
    descriptor," in Sound and Music Computing Conference (SMC’09), 2009,
    pp. 143–148.
 * 
 * Category: Spectral
 * Mode: standard
 */
class SpectralContrast extends BaseAlgorithm
{
    protected string $algorithmName = 'SpectralContrast';
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
                "Failed to compute SpectralContrast: " . $e->getMessage(),
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