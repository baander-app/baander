<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * FrequencyBands


Inputs:

  [vector_real] spectrum - the input spectrum (must be greater than size one)


Outputs:

  [vector_real] bands - the energy in each band


Parameters:

  frequencyBands:
    vector_real (default = [0, 50, 100, 150, 200, 300, 400, 510, 630, 770, 920, 1080, 1270, 1480, 1720, 2000, 2320, 2700, 3150, 3700, 4400, 5300, 6400, 7700, 9500, 12000, 15500, 20500, 27000])
    list of frequency ranges in to which the spectrum is divided (these must be
    in ascending order and connot contain duplicates)

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes energy in rectangular frequency bands of a spectrum.
  The bands are non-overlapping. For each band the power-spectrum (mag-squared)
  is summed.
  
  Parameter "frequencyBands" must contain at least 2 frequencies, they all must
  be positive and must be ordered ascentdantly, otherwise an exception will be
  thrown. FrequencyBands is only defined for spectra, which size is greater
  than 1.
  
  References:
    [1] Frequency Range - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Frequency_band
  
    [2] Band - Handbook For Acoustic Ecology,
    http://www.sfu.ca/sonic-studio/handbook/Band.html
 * 
 * Category: Spectral
 * Mode: standard
 */
class FrequencyBands extends BaseAlgorithm
{
    protected string $algorithmName = 'FrequencyBands';
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
                "Failed to compute FrequencyBands: " . $e->getMessage(),
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