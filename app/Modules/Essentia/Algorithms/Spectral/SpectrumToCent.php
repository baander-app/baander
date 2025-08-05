<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SpectrumToCent


Inputs:

  [vector_real] spectrum - the input spectrum (must be greater than size one)


Outputs:

  [vector_real] bands - the energy in each band
  [vector_real] frequencies - the central frequency of each band


Parameters:

  bands:
    integer ∈ [1,inf) (default = 720)
    number of bins to compute. Default is 720 (6 octaves with the default
    'centBinResolution')

  centBinResolution:
    real ∈ (0,inf) (default = 10)
    Width of each band in cents. Default is 10 cents

  inputSize:
    integer ∈ (1,inf) (default = 32768)
    the size of the spectrum

  log:
    bool ∈ {true,false} (default = true)
    compute log-energies (log2 (1 + energy))

  minimumFrequency:
    real ∈ (0,inf) (default = 164)
    central frequency of the first band of the bank [Hz]

  normalize:
    string ∈ {unit_sum,unit_max} (default = "unit_sum")
    use unit area or vertex equal to 1 triangles.

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  type:
    string ∈ {magnitude,power} (default = "power")
    use magnitude or power spectrum


Description:

  This algorithm computes energy in triangular frequency bands of a spectrum
  equally spaced on the cent scale. Each band is computed to have a constant
  wideness in the cent scale. For each band the power-spectrum (mag-squared) is
  summed.
  
  Parameter "centBinResolution" should be and integer greater than 1, otherwise
  an exception will be thrown. TriangularBands is only defined for spectrum,
  which size is greater than 1.
 * 
 * Category: Spectral
 * Mode: standard
 */
class SpectrumToCent extends BaseAlgorithm
{
    protected string $algorithmName = 'SpectrumToCent';
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
                "Failed to compute SpectrumToCent: " . $e->getMessage(),
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